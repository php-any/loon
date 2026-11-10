package extensions

import (
	"bufio"
	"context"
	"encoding/json"
	"net"
	"strings"
	"testing"
	"time"

	"google.golang.org/grpc"
	"google.golang.org/grpc/codes"
	"google.golang.org/grpc/metadata"
	"google.golang.org/grpc/status"
	"google.golang.org/protobuf/types/known/wrapperspb"
)

func TestTCPExchange(t *testing.T) {
	for _, tc := range []struct {
		name, reply string
		ok          bool
	}{
		{"real reply", "{\"pong\":true}\n", true},
		{"missing frame terminator", "{\"pong\":true}", false},
		{"oversized", strings.Repeat("x", maxReply+1) + "\n", false},
	} {
		t.Run(tc.name, func(t *testing.T) {
			ln, err := net.Listen("tcp", "127.0.0.1:0")
			if err != nil {
				t.Fatal(err)
			}
			defer ln.Close()
			got := make(chan string, 1)
			go func() {
				c, e := ln.Accept()
				if e != nil {
					return
				}
				defer c.Close()
				c.SetDeadline(time.Now().Add(2 * time.Second))
				line, _ := bufio.NewReader(c).ReadString('\n')
				got <- line
				c.Write([]byte(tc.reply))
			}()
			result := protocolExchange(context.Background(), "tcp", ln.Addr().String(), "", "{\"action\":\"ping\"}")
			if result["ok"] != tc.ok {
				t.Fatalf("%v", result)
			}
			if line := <-got; line != "{\"action\":\"ping\"}\n" {
				t.Fatalf("wire %q", line)
			}
			if tc.ok && strings.TrimSpace(string(result["response"].(json.RawMessage))) != "{\"pong\":true}" {
				t.Fatal(result)
			}
		})
	}
}

func TestGRPCExchange(t *testing.T) {
	ln, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	srv := grpc.NewServer()
	defer srv.Stop()
	srv.RegisterService(&grpc.ServiceDesc{ServiceName: "test.Echo", HandlerType: (*interface{})(nil), Methods: []grpc.MethodDesc{{MethodName: "Say", Handler: func(_ any, _ context.Context, decode func(any) error, _ grpc.UnaryServerInterceptor) (any, error) {
		input := new(wrapperspb.StringValue)
		if err := decode(input); err != nil {
			return nil, err
		}
		if input.Value == "" {
			return nil, status.Error(codes.InvalidArgument, "name required")
		}
		return wrapperspb.String("wire:" + input.Value), nil
	}}}}, struct{}{})
	go srv.Serve(ln)
	good := protocolExchange(context.Background(), "grpc", ln.Addr().String(), "/test.Echo/Say", `"Ada"`)
	if good["ok"] != true || string(good["response"].(json.RawMessage)) != `"wire:Ada"` {
		t.Fatal(good)
	}
	bad := protocolExchange(context.Background(), "grpc", ln.Addr().String(), "/test.Echo/Say", `""`)
	if bad["ok"] != false || bad["status"] != "InvalidArgument" {
		t.Fatal(bad)
	}
	srv.Stop()
	down := protocolExchange(context.Background(), "grpc", ln.Addr().String(), "/test.Echo/Say", `"Ada"`)
	if down["ok"] != false {
		t.Fatal(down)
	}
}

func TestTCPExchangeStampsCorrelationOnWire(t *testing.T) {
	ln, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	defer ln.Close()
	got := make(chan string, 1)
	go func() {
		c, e := ln.Accept()
		if e != nil {
			return
		}
		defer c.Close()
		c.SetDeadline(time.Now().Add(2 * time.Second))
		line, _ := bufio.NewReader(c).ReadString('\n')
		got <- line
		_, _ = c.Write([]byte("{\"pong\":true}\n"))
	}()
	parent := context.WithValue(context.Background(), correlationKey{}, "trace-tcp-wire")
	result := protocolExchange(parent, "tcp", ln.Addr().String(), "", `{"action":"ping"}`)
	if result["ok"] != true {
		t.Fatalf("%v", result)
	}
	line := <-got
	if !strings.Contains(line, `"_loom_correlation_id":"trace-tcp-wire"`) {
		t.Fatalf("wire missing correlation stamp: %q", line)
	}
}

func TestGRPCExchangeSendsOutgoingMetadata(t *testing.T) {
	ln, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	gotMeta := make(chan string, 1)
	srv := grpc.NewServer()
	defer srv.Stop()
	srv.RegisterService(&grpc.ServiceDesc{ServiceName: "test.Echo", HandlerType: (*interface{})(nil), Methods: []grpc.MethodDesc{{MethodName: "Say", Handler: func(_ any, ctx context.Context, decode func(any) error, _ grpc.UnaryServerInterceptor) (any, error) {
		input := new(wrapperspb.StringValue)
		if err := decode(input); err != nil {
			return nil, err
		}
		if md, ok := metadata.FromIncomingContext(ctx); ok {
			if vals := md.Get("x-loom-correlation-id"); len(vals) > 0 {
				gotMeta <- vals[0]
			}
		}
		return wrapperspb.String("wire:" + input.Value), nil
	}}}}, struct{}{})
	go srv.Serve(ln)
	parent := context.WithValue(context.Background(), correlationKey{}, "trace-grpc-out")
	wire := `"{\"total\":1}"`
	result := protocolExchange(parent, "grpc", ln.Addr().String(), "/test.Echo/Say", wire)
	if result["ok"] != true {
		t.Fatalf("%v", result)
	}
	select {
	case meta := <-gotMeta:
		if meta != "trace-grpc-out" {
			t.Fatalf("metadata %q", meta)
		}
	case <-time.After(2 * time.Second):
		t.Fatal("timeout waiting for incoming metadata")
	}
}

func TestCancelledExchange(t *testing.T) {
	ctx, cancel := context.WithCancel(context.Background())
	cancel()
	for _, proto := range []string{"tcp", "grpc"} {
		result := protocolExchange(ctx, proto, "127.0.0.1:1", "/test.Echo/Say", `"Ada"`)
		if result["ok"] != false {
			t.Fatal(result)
		}
	}
}
