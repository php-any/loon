package loom

import (
	"bufio"
	"context"
	"fmt"
	"net"
	"net/http"
	"sync"

	"github.com/gorilla/websocket"
	"github.com/php-any/origami/data"
	"github.com/php-any/origami/runtime"
	nethttp "github.com/php-any/origami/std/net/http"
	"github.com/php-any/origami/utils"
)

type websocketKey struct{}

func isWebSocketRequest(r *http.Request) bool { return websocket.IsWebSocketUpgrade(r) }

// Only the explicit WebSocket bridge receives this writer. PHP's ordinary
// output remains bound to captureWriter and retains the Response contract.
type websocketScope struct {
	http.ResponseWriter
	config    Config
	committed bool
	conn      net.Conn
	stop      func() bool
	mu        sync.Mutex
}

func (s *websocketScope) WriteHeader(code int) {
	s.committed = true
	s.ResponseWriter.WriteHeader(code)
}
func (s *websocketScope) Write(b []byte) (int, error) {
	s.committed = true
	return s.ResponseWriter.Write(b)
}
func (s *websocketScope) Hijack() (net.Conn, *bufio.ReadWriter, error) {
	h, ok := s.ResponseWriter.(http.Hijacker)
	if !ok {
		return nil, nil, fmt.Errorf("WebSocket requires an HTTP/1.1 hijackable connection")
	}
	c, rw, err := h.Hijack()
	if err == nil {
		s.committed = true
		s.conn = c
	}
	return c, rw, err
}
func (s *websocketScope) close() {
	s.mu.Lock()
	defer s.mu.Unlock()
	if s.stop != nil {
		s.stop()
	}
	if s.conn != nil {
		_ = s.conn.Close()
	}
}

type websocketFunction struct{ op string }

func (f *websocketFunction) GetName() string { return "Loom\\websocket_" + f.op }
func (f *websocketFunction) GetParams() []data.GetValue {
	if f.op == "configure" {
		return []data.GetValue{data.NewParameter("connection", 0)}
	}
	return nil
}
func (f *websocketFunction) GetVariables() []data.Variable {
	if f.op == "configure" {
		return []data.Variable{data.NewVariable("connection", 0, nil)}
	}
	return nil
}
func (f *websocketFunction) Call(ctx data.Context) (data.GetValue, data.Control) {
	vm, ok := ctx.GetVM().(*runtime.RequestVM)
	if !ok || vm.HTTPRequest() == nil {
		return nil, utils.NewThrow(fmt.Errorf("WebSocket requires an HTTP request"))
	}
	r := vm.HTTPRequest()
	s, ok := r.Context().Value(websocketKey{}).(*websocketScope)
	if !ok {
		return nil, utils.NewThrow(fmt.Errorf("WebSocket upgrade was not requested"))
	}
	switch f.op {
	case "request":
		if s.committed {
			return nil, utils.NewThrow(fmt.Errorf("WebSocket upgrade already attempted"))
		}
		return data.NewProxyValue(nethttp.NewRequestClassFrom(r), ctx.CreateBaseContext()), nil
	case "response":
		return data.NewProxyValue(nethttp.NewResponseWriterClassFrom(s), ctx.CreateBaseContext()), nil
	case "configure":
		conn, err := utils.ConvertFromIndex[*websocket.Conn](ctx, 0)
		if err != nil {
			return nil, utils.NewThrow(err)
		}
		conn.SetReadLimit(s.config.MaxWebSocketMessageBytes)
		deadline, _ := r.Context().Deadline()
		if err = conn.SetReadDeadline(deadline); err != nil {
			return nil, utils.NewThrow(err)
		}
		if err = conn.SetWriteDeadline(deadline); err != nil {
			return nil, utils.NewThrow(err)
		}
		s.mu.Lock()
		s.stop = context.AfterFunc(r.Context(), func() { _ = conn.Close() })
		s.mu.Unlock()
		return nil, nil
	}
	return nil, utils.NewThrow(fmt.Errorf("Unknown WebSocket operation"))
}
