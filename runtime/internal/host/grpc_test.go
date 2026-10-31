package host

import (
	"context"
	"encoding/json"
	"net"
	"strings"
	"sync"
	"testing"
	"time"

	"github.com/php-any/loom/framework"
	"github.com/php-any/loom/protocol"
	"google.golang.org/grpc"
	"google.golang.org/grpc/codes"
	"google.golang.org/grpc/credentials/insecure"
	"google.golang.org/grpc/encoding/gzip"
	reflectionv1 "google.golang.org/grpc/reflection/grpc_reflection_v1"
	"google.golang.org/grpc/stats"
	"google.golang.org/grpc/status"
	"google.golang.org/protobuf/types/dynamicpb"
)

type compressionStats struct {
	mu      sync.Mutex
	in, out bool
}

func (*compressionStats) TagRPC(ctx context.Context, _ *stats.RPCTagInfo) context.Context { return ctx }
func (s *compressionStats) HandleRPC(_ context.Context, event stats.RPCStats) {
	s.mu.Lock()
	defer s.mu.Unlock()
	switch e := event.(type) {
	case *stats.InPayload:
		s.in = s.in || e.CompressedLength < e.Length
	case *stats.OutPayload:
		s.out = s.out || e.CompressedLength < e.Length
	}
}
func (*compressionStats) TagConn(ctx context.Context, _ *stats.ConnTagInfo) context.Context {
	return ctx
}
func (*compressionStats) HandleConn(context.Context, stats.ConnStats) {}

func TestDynamicGRPCAndCompression(t *testing.T) {
	h, err := loom.NewHandler(loom.Config{Entry: "bootstrap/grpc.php"})
	if err != nil {
		t.Fatal(err)
	}
	listener, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	metrics := new(compressionStats)
	server := grpc.NewServer(grpc.StatsHandler(metrics), grpc.MaxRecvMsgSize(1<<20))
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
	schema, err := protocol.Load(context.Background(), []string{"proto"}, []string{"greeter.proto"})
	if err != nil {
		t.Fatal(err)
	}
	ctx, cancel := context.WithTimeout(context.Background(), 20*time.Second)
	defer cancel()
	call := func(method, payload string, code codes.Code) *dynamicpb.Message {
		t.Helper()
		m, err := schema.Method("loom.examples.Greeter", method)
		if err != nil {
			t.Fatal(err)
		}
		request, err := protocol.NewRequest(m, []byte(payload))
		if err != nil {
			t.Fatal(err)
		}
		response := dynamicpb.NewMessage(m.Output())
		err = conn.Invoke(ctx, "/loom.examples.Greeter/"+method, request, response, grpc.UseCompressor(gzip.Name))
		if status.Code(err) != code {
			t.Fatalf("%s: %v", method, err)
		}
		return response
	}
	result := call("Greet", `{"name":"Ada","times":3}`, codes.OK)
	if result.Get(result.Descriptor().Fields().ByName("greetings")).List().Len() != 3 {
		t.Fatal("wrong repeat count")
	}
	call("Greet", `{"name":"Ada","times":0}`, codes.InvalidArgument)
	call("Greet", `{"name":""}`, codes.InvalidArgument)
	call("BatchGreet", `{"names":[]}`, codes.InvalidArgument)
	names := make([]string, 500)
	for i := range names {
		names[i] = "Ada-compression-example"
	}
	payload, _ := json.Marshal(map[string]any{"names": names})
	batch := call("BatchGreet", string(payload), codes.OK)
	if batch.Get(batch.Descriptor().Fields().ByName("count")).Int() != 500 {
		t.Fatal("wrong batch count")
	}
	metrics.mu.Lock()
	in, out := metrics.in, metrics.out
	metrics.mu.Unlock()
	if !in || !out {
		t.Fatalf("gzip not observed: request=%t response=%t", in, out)
	}
	// Decompressed length must be checked even when the transmitted data is tiny.
	payload, _ = json.Marshal(map[string]string{"name": strings.Repeat("x", (1<<20)+100)})
	call("Greet", string(payload), codes.ResourceExhausted)
	stream, err := reflectionv1.NewServerReflectionClient(conn).ServerReflectionInfo(ctx)
	if err != nil {
		t.Fatal(err)
	}
	if err := stream.Send(&reflectionv1.ServerReflectionRequest{MessageRequest: &reflectionv1.ServerReflectionRequest_FileContainingSymbol{FileContainingSymbol: "loom.examples.Greeter"}}); err != nil {
		t.Fatal(err)
	}
	reply, err := stream.Recv()
	if err != nil || reply.GetFileDescriptorResponse() == nil || len(reply.GetFileDescriptorResponse().FileDescriptorProto) < 2 {
		t.Fatalf("reflection imports: %v %v", reply, err)
	}
	stream.CloseSend()
}
