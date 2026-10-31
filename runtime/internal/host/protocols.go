package host

import (
	"context"
	"fmt"
	"log"
	"net"
	"sync"
	"time"

	"github.com/php-any/loom/framework"
	"google.golang.org/grpc"
)

type protocolConfig struct {
	Address     string   `json:"address"`
	Entry       string   `json:"entry"`
	ImportPaths []string `json:"import_paths,omitempty"`
	ProtoFiles  []string `json:"proto_files,omitempty"`
	Reflection  bool     `json:"reflection,omitempty"`
}

// startProtocols binds every enabled listener before starting any goroutines.
// Failure unwinds all listeners. The returned function cancels and joins them.
func startProtocols(parent context.Context, config projectConfig, services *loom.Services, onFailure func()) (func(), error) {
	ctx, cancel := context.WithCancel(parent)
	var listeners []net.Listener
	var starts []func()
	var workers sync.WaitGroup
	cleanup := func() {
		cancel()
		for _, l := range listeners {
			_ = l.Close()
		}
		workers.Wait()
	}
	for _, item := range []struct {
		name   string
		config *protocolConfig
	}{{"tcp", config.TCP}, {"grpc", config.GRPC}} {
		if item.config == nil {
			continue
		}
		handler, err := loom.NewHandler(loom.Config{Entry: item.config.Entry, Services: services})
		if err != nil {
			cleanup()
			return nil, err
		}
		listener, err := net.Listen("tcp", item.config.Address)
		if err != nil {
			cleanup()
			return nil, fmt.Errorf("%s listener: %w", item.name, err)
		}
		listeners = append(listeners, listener)
		if item.name == "tcp" {
			starts = append(starts, func() {
				if err := handler.ServeTCP(ctx, listener); err != nil {
					log.Printf("TCP: %v", err)
					cancel()
					if onFailure != nil {
						onFailure()
					}
				}
			})
		} else {
			server := grpc.NewServer(grpc.MaxRecvMsgSize(1<<20), grpc.MaxSendMsgSize(4<<20), grpc.MaxConcurrentStreams(64))
			if err := registerApplicationGRPC(ctx, server, handler, item.config); err != nil {
				cleanup()
				return nil, err
			}
			starts = append(starts, func() {
				done := make(chan struct{})
				go func() {
					defer close(done)
					<-ctx.Done()
					timer := time.AfterFunc(15*time.Second, server.Stop)
					server.GracefulStop()
					timer.Stop()
				}()
				if err := server.Serve(listener); err != nil && ctx.Err() == nil {
					log.Printf("gRPC: %v", err)
					if onFailure != nil {
						onFailure()
					}
				}
				cancel()
				<-done
			})
		}
		log.Printf("Loom %s: %s", item.name, listener.Addr())
	}
	for _, start := range starts {
		workers.Add(1)
		go func() { defer workers.Done(); start() }()
	}
	return cleanup, nil
}
