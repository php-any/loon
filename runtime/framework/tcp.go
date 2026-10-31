package loom

import (
	"bufio"
	"context"
	"encoding/json"
	"errors"
	"net"
	"sync"
	"time"
)

// ServeTCP serves newline-delimited JSON, one isolated PHP call per frame.
// It owns listener and closes all connections before returning on cancellation.
func (h *Handler) ServeTCP(ctx context.Context, listener net.Listener) error {
	ctx, cancel := context.WithCancel(ctx)
	stop := context.AfterFunc(ctx, func() { _ = listener.Close() })
	defer stop()
	var workers sync.WaitGroup
	defer workers.Wait()
	defer cancel()
	slots := make(chan struct{}, h.config.MaxConcurrentRequests)
	for {
		conn, err := listener.Accept()
		if err != nil {
			if ctx.Err() != nil || errors.Is(err, net.ErrClosed) {
				return nil
			}
			return err
		}
		select {
		case slots <- struct{}{}:
		default:
			_ = conn.Close()
			continue
		}
		workers.Add(1)
		go func() {
			defer workers.Done()
			defer func() { <-slots }()
			defer conn.Close()
			stop := context.AfterFunc(ctx, func() { _ = conn.Close() })
			defer stop()
			scanner := bufio.NewScanner(conn)
			scanner.Buffer(make([]byte, min(4096, int(h.config.MaxBodyBytes)+2)), int(h.config.MaxBodyBytes)+2)
			for {
				_ = conn.SetReadDeadline(time.Now().Add(60 * time.Second))
				if !scanner.Scan() {
					return
				}
				payload := scanner.Bytes()
				var object map[string]json.RawMessage
				var reply []byte
				if len(payload) > int(h.config.MaxBodyBytes) || json.Unmarshal(payload, &object) != nil || object == nil {
					reply = []byte(`{"ok":false,"error":"invalid_message"}`)
				} else {
					result, err := h.RunMessage(ctx, payload)
					if err != nil {
						h.config.OnError(err)
						reply = []byte(`{"ok":false,"error":"internal_error"}`)
					} else {
						reply = result
					}
				}
				_ = conn.SetWriteDeadline(time.Now().Add(10 * time.Second))
				if _, err := conn.Write(append(reply, '\n')); err != nil {
					return
				}
			}
		}()
	}
}
