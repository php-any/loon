package host

import (
	"context"
	"net"
	"testing"
	"time"

	"github.com/php-any/loom/framework"
	"github.com/php-any/loom/protocol"
	"google.golang.org/grpc"
	"google.golang.org/grpc/codes"
	"google.golang.org/grpc/credentials/insecure"
	"google.golang.org/grpc/status"
	"google.golang.org/protobuf/types/dynamicpb"
)

func TestGeneratedUserServiceRegistrationAndErrors(t *testing.T) {
	h, err := loom.NewHandler(loom.Config{Entry: "bootstrap/grpc.php"})
	if err != nil {
		t.Fatal(err)
	}
	listener, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	defer listener.Close()
	server := grpc.NewServer()
	defer server.Stop()
	// Config lists only the legacy Greeter: UserService must come from the manifest.
	if err := registerApplicationGRPC(context.Background(), server, h, &protocolConfig{ImportPaths: []string{"proto"}, ProtoFiles: []string{"greeter.proto"}}); err != nil {
		t.Fatal(err)
	}
	go server.Serve(listener)
	conn, err := grpc.NewClient(listener.Addr().String(), grpc.WithTransportCredentials(insecure.NewCredentials()))
	if err != nil {
		t.Fatal(err)
	}
	defer conn.Close()
	schema, err := protocol.Load(context.Background(), []string{"proto"}, []string{"user.proto"})
	if err != nil {
		t.Fatal(err)
	}
	method, err := schema.Method("app.users.UserService", "GetUser")
	if err != nil {
		t.Fatal(err)
	}
	ctx, cancel := context.WithTimeout(context.Background(), 10*time.Second)
	defer cancel()
	for _, payload := range []string{`{"id":7}`, `{"id":0}`} {
		request, err := protocol.NewRequest(method, []byte(payload))
		if err != nil {
			t.Fatal(err)
		}
		response := dynamicpb.NewMessage(method.Output())
		err = conn.Invoke(ctx, "/app.users.UserService/GetUser", request, response)
		if payload == `{"id":0}` {
			if status.Code(err) != codes.InvalidArgument {
				t.Fatalf("business exception: %v", err)
			}
			continue
		}
		if err != nil {
			t.Fatal(err)
		}
		if response.Get(response.Descriptor().Fields().ByName("id")).Int() != 7 || response.Get(response.Descriptor().Fields().ByName("name")).String() != "示例用户" {
			t.Fatalf("bad reply: %v", response)
		}
	}
}
