package host

import (
	"bufio"
	"context"
	"encoding/json"
	"net"
	"net/http/httptest"
	"strings"
	"testing"
	"time"

	"github.com/gorilla/websocket"
	"github.com/php-any/loom/framework"
	"google.golang.org/grpc"
	"google.golang.org/grpc/codes"
	"google.golang.org/grpc/credentials/insecure"
	"google.golang.org/grpc/status"
	"google.golang.org/protobuf/types/known/wrapperspb"
)

func TestTCPApplication(t *testing.T) {
	h, err := loom.NewHandler(loom.Config{Entry: "bootstrap/tcp.php"})
	if err != nil {
		t.Fatal(err)
	}
	listener, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	done := make(chan error, 1)
	go func() { done <- h.ServeTCP(ctx, listener) }()
	conn, err := net.DialTimeout("tcp", listener.Addr().String(), time.Second)
	if err != nil {
		t.Fatal(err)
	}
	defer conn.Close()
	_ = conn.SetDeadline(time.Now().Add(15 * time.Second))
	reader := bufio.NewReader(conn)
	for _, tc := range []struct{ request, want string }{
		{`{"action":"ping"}`, `"pong":true`},
		{`{"action":"greet","name":"Ada"}`, "Hello, Ada"},
		{`{"action":"greet","name":""}`, "invalid_name"},
		{`{"action":"missing"}`, "unknown_action"},
		{`bad json`, "invalid_message"},
		{`[]`, "invalid_message"},
		{`{"action":"greet","name":"Grace"}`, "Hello, Grace"},
	} {
		if _, err := conn.Write([]byte(tc.request + "\n")); err != nil {
			t.Fatal(err)
		}
		result, err := reader.ReadString('\n')
		if err != nil {
			t.Fatal(err)
		}
		if !json.Valid([]byte(result)) || !strings.Contains(result, tc.want) {
			t.Fatalf("%s => %s", tc.request, result)
		}
	}
	// One JSON frame fragmented across writes followed by a second coalesced frame.
	if _, err := conn.Write([]byte(`{"action":"gr`)); err != nil {
		t.Fatal(err)
	}
	if _, err := conn.Write([]byte("eet\",\"name\":\"Split\"}\n{\"action\":\"ping\"}\n")); err != nil {
		t.Fatal(err)
	}
	for _, want := range []string{"Hello, Split", "pong"} {
		line, err := reader.ReadString('\n')
		if err != nil || !strings.Contains(line, want) {
			t.Fatalf("framing: %s %v", line, err)
		}
	}
	cancel()
	select {
	case err := <-done:
		if err != nil {
			t.Fatal(err)
		}
	case <-time.After(3 * time.Second):
		t.Fatal("TCP shutdown stuck on idle connection")
	}
}

func TestWebSocketGreetingApplication(t *testing.T) {
	h, err := newHandler("bootstrap/http.php")
	if err != nil {
		t.Fatal(err)
	}
	server := httptest.NewServer(h)
	defer server.Close()
	for _, name := range []string{"Ada", ""} {
		conn, _, err := websocket.DefaultDialer.Dial("ws"+strings.TrimPrefix(server.URL, "http")+"/ws/greet", nil)
		if err != nil {
			t.Fatal(err)
		}
		_ = conn.SetReadDeadline(time.Now().Add(10 * time.Second))
		if err := conn.WriteMessage(websocket.TextMessage, []byte(name)); err != nil {
			conn.Close()
			t.Fatal(err)
		}
		_, reply, err := conn.ReadMessage()
		conn.Close()
		want := "Hello, Ada"
		if name == "" {
			want = "invalid_name"
		}
		if err != nil || !strings.Contains(string(reply), want) {
			t.Fatalf("reply=%s error=%v", reply, err)
		}
	}
}

func TestProtocolStartupRollback(t *testing.T) {
	// Occupied gRPC address must unwind an already-created TCP listener.
	occupied, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	defer occupied.Close()
	free, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	address := free.Addr().String()
	free.Close()
	config := projectConfig{TCP: &protocolConfig{Address: address, Entry: "bootstrap/tcp.php"}, GRPC: &protocolConfig{Address: occupied.Addr().String(), Entry: "bootstrap/grpc.php"}}
	stop, err := startProtocols(context.Background(), config, nil, nil)
	if err == nil {
		stop()
		t.Fatal("expected bind failure")
	}
	rebound, err := net.Listen("tcp", address)
	if err != nil {
		t.Fatalf("TCP listener leaked: %v", err)
	}
	rebound.Close()
	config.GRPC = nil
	stop, err = startProtocols(context.Background(), config, nil, nil)
	if err != nil {
		t.Fatal(err)
	}
	stop()
	rebound, err = net.Listen("tcp", address)
	if err != nil {
		t.Fatalf("TCP cleanup leaked listener: %v", err)
	}
	rebound.Close()
}

func TestGRPCApplication(t *testing.T) {
	h, err := loom.NewHandler(loom.Config{Entry: "bootstrap/grpc.php"})
	if err != nil {
		t.Fatal(err)
	}
	listener, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	server := grpc.NewServer()
	if err := registerApplicationGRPC(context.Background(), server, h, &protocolConfig{ImportPaths: []string{"proto"}, ProtoFiles: []string{"greeter.proto"}, Reflection: true}); err != nil {
		t.Fatal(err)
	}
	go server.Serve(listener)
	defer server.Stop()
	conn, err := grpc.NewClient(listener.Addr().String(), grpc.WithTransportCredentials(insecure.NewCredentials()))
	if err != nil {
		t.Fatal(err)
	}
	defer conn.Close()
	ctx, cancel := context.WithTimeout(context.Background(), 15*time.Second)
	defer cancel()
	for _, name := range []string{"Ada", "Grace", ""} {
		response := new(wrapperspb.StringValue)
		err := conn.Invoke(ctx, "/loom.examples.Greeter/SayHello", wrapperspb.String(name), response)
		if name == "" {
			if status.Code(err) != codes.InvalidArgument {
				t.Fatalf("invalid name: %v", err)
			}
			continue
		}
		if err != nil || response.Value != "Hello, "+name {
			t.Fatalf("gRPC response %v, error %v", response, err)
		}
	}
	if err := conn.Invoke(ctx, "/loom.examples.Greeter/Missing", wrapperspb.String("Ada"), new(wrapperspb.StringValue)); status.Code(err) != codes.Unimplemented {
		t.Fatalf("unknown method: %v", err)
	}
}
