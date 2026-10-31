// protocol-client exercises the example services started by loom.
package main

import (
	"bufio"
	"context"
	"encoding/json"
	"flag"
	"fmt"
	"log"
	"net"
	"strings"
	"time"

	"github.com/gorilla/websocket"
)

func main() {
	protocol := flag.String("protocol", "tcp", "tcp, grpc, websocket, proto or all")
	address := flag.String("addr", "", "override service address")
	httpAddress := flag.String("http-addr", "127.0.0.1:18087", "HTTP address for all-protocol demo")
	tcpAddress := flag.String("tcp-addr", "127.0.0.1:18088", "TCP address for all-protocol demo")
	wsURL := flag.String("websocket-url", "ws://127.0.0.1:18087/ws/greet", "WebSocket URL for all-protocol demo")
	name := flag.String("name", "Ada", "greeting name")
	protoDir := flag.String("proto-dir", "../proto", "protobuf import directory")
	protoFile := flag.String("proto-file", "greeter.proto", "protobuf entry file")
	method := flag.String("method", "SayHello", "method on loom.examples.Greeter")
	data := flag.String("data", "", "ProtoJSON request; defaults to name for SayHello")
	compression := flag.Bool("gzip", false, "compress gRPC requests and responses")
	demo := flag.Bool("demo", false, "run and verify all gRPC examples, errors and compression comparison")
	flag.Parse()
	if *protocol == "proto" {
		if err := inspectProto(*protoDir, *protoFile); err != nil {
			log.Fatal(err)
		}
		return
	}
	if *protocol == "grpc" || *protocol == "all" {
		if *protocol == "all" {
			if err := demoHTTP(*httpAddress); err != nil {
				log.Fatal(err)
			}
			for _, target := range []struct{ protocol, address string }{{"tcp", *tcpAddress}, {"websocket", *wsURL}} {
				if err := run(target.protocol, target.address, *name); err != nil {
					log.Fatal(err)
				}
			}
			*demo = true
		}
		if *data == "" {
			payload, _ := json.Marshal(*name)
			*data = string(payload)
		}
		if err := runGRPC(*address, *protoDir, *protoFile, *method, *data, *compression, *demo); err != nil {
			log.Fatal(err)
		}
		return
	}
	if err := run(*protocol, *address, *name); err != nil {
		log.Fatal(err)
	}
}

func run(protocol, address, name string) error {
	ctx, cancel := context.WithTimeout(context.Background(), 10*time.Second)
	defer cancel()
	switch protocol {
	case "tcp":
		if address == "" {
			address = "127.0.0.1:18088"
		}
		conn, err := (&net.Dialer{}).DialContext(ctx, "tcp", address)
		if err != nil {
			return err
		}
		defer conn.Close()
		_ = conn.SetDeadline(time.Now().Add(10 * time.Second))
		reader := bufio.NewReader(conn)
		// Several frames on one connection, including an application error.
		for _, message := range []map[string]string{{"action": "ping"}, {"action": "greet", "name": name}, {"action": "unknown"}} {
			if err := json.NewEncoder(conn).Encode(message); err != nil {
				return err
			}
			response, err := reader.ReadString('\n')
			if err != nil {
				return err
			}
			var reply struct {
				OK     bool   `json:"ok"`
				Error  string `json:"error"`
				Result struct {
					Message string `json:"message"`
					Pong    bool   `json:"pong"`
				} `json:"result"`
			}
			if err := json.Unmarshal([]byte(response), &reply); err != nil {
				return err
			}
			switch message["action"] {
			case "ping":
				if !reply.OK || !reply.Result.Pong {
					return fmt.Errorf("unexpected ping result: %s", response)
				}
			case "greet":
				if !reply.OK || reply.Result.Message != "Hello, "+strings.TrimSpace(name) {
					return fmt.Errorf("unexpected greeting: %s", response)
				}
			case "unknown":
				if reply.OK || reply.Error != "unknown_action" {
					return fmt.Errorf("unexpected error response: %s", response)
				}
			}
			fmt.Print(response)
		}
	case "websocket":
		if address == "" {
			address = "ws://127.0.0.1:18087/ws/greet"
		}
		conn, _, err := websocket.DefaultDialer.DialContext(ctx, address, nil)
		if err != nil {
			return err
		}
		defer conn.Close()
		_ = conn.SetReadDeadline(time.Now().Add(10 * time.Second))
		_ = conn.SetWriteDeadline(time.Now().Add(10 * time.Second))
		if err := conn.WriteMessage(websocket.TextMessage, []byte(name)); err != nil {
			return err
		}
		_, message, err := conn.ReadMessage()
		if err != nil {
			return err
		}
		if strings.HasSuffix(address, "/ws/greet") {
			var reply struct {
				Message string `json:"message"`
			}
			if err := json.Unmarshal(message, &reply); err != nil {
				return err
			}
			if reply.Message != "Hello, "+strings.TrimSpace(name) {
				return fmt.Errorf("unexpected websocket greeting: %s", message)
			}
		}
		fmt.Println(string(message))
	default:
		return fmt.Errorf("unknown protocol: %s", protocol)
	}
	return nil
}
