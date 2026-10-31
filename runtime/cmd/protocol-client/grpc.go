package main

import (
	"context"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"strings"
	"sync"
	"time"

	"github.com/php-any/loom/protocol"
	"google.golang.org/grpc"
	"google.golang.org/grpc/codes"
	"google.golang.org/grpc/credentials/insecure"
	"google.golang.org/grpc/encoding/gzip"
	"google.golang.org/grpc/stats"
	"google.golang.org/grpc/status"
	"google.golang.org/protobuf/encoding/protojson"
	"google.golang.org/protobuf/types/dynamicpb"
)

type payloadStats struct {
	mu             sync.Mutex
	sent, received int
}

func (s *payloadStats) TagRPC(ctx context.Context, _ *stats.RPCTagInfo) context.Context { return ctx }
func (s *payloadStats) HandleRPC(_ context.Context, event stats.RPCStats) {
	s.mu.Lock()
	defer s.mu.Unlock()
	switch e := event.(type) {
	case *stats.OutPayload:
		s.sent += e.CompressedLength
	case *stats.InPayload:
		s.received += e.CompressedLength
	}
}
func (*payloadStats) TagConn(ctx context.Context, _ *stats.ConnTagInfo) context.Context { return ctx }
func (*payloadStats) HandleConn(context.Context, stats.ConnStats)                       {}
func (s *payloadStats) snapshot() (int, int) {
	s.mu.Lock()
	defer s.mu.Unlock()
	return s.sent, s.received
}

func inspectProto(root, file string) error {
	schema, err := protocol.Load(context.Background(), []string{root}, []string{file})
	if err != nil {
		return err
	}
	for _, service := range schema.Services {
		fmt.Printf("service %s (%s)\n", service.FullName(), service.ParentFile().Path())
		for i := 0; i < service.Methods().Len(); i++ {
			m := service.Methods().Get(i)
			fmt.Printf("  %s(%s) -> %s\n", m.Name(), m.Input().FullName(), m.Output().FullName())
		}
	}
	return nil
}

func runGRPC(address, root, file, service, method, data string, compressed, demo bool) error {
	if demo && service != "loom.examples.Greeter" {
		return fmt.Errorf("--demo uses loom.examples.Greeter; use --method and --data for other services")
	}
	if address == "" {
		address = "127.0.0.1:18089"
	}
	schema, err := protocol.Load(context.Background(), []string{root}, []string{file})
	if err != nil {
		return err
	}
	metrics := new(payloadStats)
	conn, err := grpc.NewClient(address, grpc.WithTransportCredentials(insecure.NewCredentials()), grpc.WithStatsHandler(metrics))
	if err != nil {
		return err
	}
	defer conn.Close()
	call := func(method, payload string, compressed bool, expected codes.Code, verbose bool) (int, int, error) {
		descriptor, err := schema.Method(service, method)
		if err != nil {
			return 0, 0, err
		}
		request, err := protocol.NewRequest(descriptor, []byte(payload))
		if err != nil {
			return 0, 0, err
		}
		response := dynamicpb.NewMessage(descriptor.Output())
		var options []grpc.CallOption
		if compressed {
			options = append(options, grpc.UseCompressor(gzip.Name))
		}
		ctx, cancel := context.WithTimeout(context.Background(), 10*time.Second)
		defer cancel()
		beforeSent, beforeReceived := metrics.snapshot()
		err = conn.Invoke(ctx, "/"+service+"/"+method, request, response, options...)
		if status.Code(err) != expected {
			return 0, 0, fmt.Errorf("%s: expected %s, got %v", method, expected, err)
		}
		sent, received := metrics.snapshot()
		sent -= beforeSent
		received -= beforeReceived
		fmt.Printf("%s gzip=%t status=%s payload_bytes sent=%d received=%d\n", method, compressed, expected, sent, received)
		if err == nil && verbose {
			raw, err := protojson.Marshal(response)
			if err != nil {
				return 0, 0, err
			}
			fmt.Println(string(raw))
		}
		if err == nil && service == "loom.examples.Greeter" && method == "BatchGreet" {
			var input struct {
				Names []string `json:"names"`
			}
			_ = json.Unmarshal([]byte(payload), &input)
			output := response.ProtoReflect()
			if int(output.Get(output.Descriptor().Fields().ByName("count")).Int()) != len(input.Names) || output.Get(output.Descriptor().Fields().ByName("greetings")).List().Len() != len(input.Names) {
				return 0, 0, fmt.Errorf("batch result count mismatch")
			}
		}
		return sent, received, nil
	}
	if !demo {
		_, _, err := call(method, data, compressed, codes.OK, true)
		return err
	}
	if err := inspectProto(root, file); err != nil {
		return err
	}
	for _, tc := range []struct {
		method, data string
		code         codes.Code
	}{
		{"SayHello", `"Ada"`, codes.OK},
		{"Greet", `{"name":"Ada","times":3}`, codes.OK},
		{"BatchGreet", `{"names":["Ada","Grace","Linus"]}`, codes.OK},
		{"Greet", `{"name":"","times":3}`, codes.InvalidArgument},
		{"BatchGreet", `{"names":[]}`, codes.InvalidArgument},
	} {
		if _, _, err := call(tc.method, tc.data, true, tc.code, true); err != nil {
			return err
		}
	}
	names := make([]string, 500)
	for i := range names {
		names[i] = "compression-demo-Ada"
	}
	payload, _ := json.Marshal(map[string]any{"names": names})
	plainSent, plainReceived, err := call("BatchGreet", string(payload), false, codes.OK, false)
	if err != nil {
		return err
	}
	zipSent, zipReceived, err := call("BatchGreet", string(payload), true, codes.OK, false)
	if err != nil {
		return err
	}
	if zipSent >= plainSent || zipReceived >= plainReceived {
		return fmt.Errorf("expected repeated batch data to compress in both directions")
	}
	fmt.Println("PASS: proto imports, dynamic methods, PHP dispatch, validation and gzip round trip")
	return nil
}

func demoHTTP(address string) error {
	client := &http.Client{Timeout: 10 * time.Second}
	response, err := client.Post("http://"+address+"/greetings", "application/json", strings.NewReader(`{"name":"Ada","times":2}`))
	if err != nil {
		return err
	}
	defer response.Body.Close()
	body, err := io.ReadAll(response.Body)
	if err != nil {
		return err
	}
	if response.StatusCode != 201 || !strings.Contains(string(body), "Hello, Ada") {
		return fmt.Errorf("HTTP demo failed: %d %s", response.StatusCode, body)
	}
	fmt.Printf("HTTP 201 %s\n", body)
	return nil
}
