package extensions

import (
	"bufio"
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"io"
	"net"
	"net/http"
	"sync"
	"time"

	"github.com/gorilla/websocket"
	"github.com/php-any/origami/data"
	"github.com/php-any/origami/utils"
	"golang.org/x/net/netutil"
)

// Hijack 成功后，所有权从 HTTP 请求转移到连接。
// 其生命周期仍可取消，但不再受 HTTP 响应截止时间限制。
type upgradeWriter struct {
	http.ResponseWriter
	timer       *time.Timer
	cancel      context.CancelFunc
	release     func()
	connections *sync.Map
}

func (w *upgradeWriter) Hijack() (net.Conn, *bufio.ReadWriter, error) {
	hijacker, ok := w.ResponseWriter.(http.Hijacker)
	if !ok {
		return nil, nil, errors.New("HTTP transport does not support hijacking")
	}
	connection, buffer, err := hijacker.Hijack()
	if err != nil {
		return nil, nil, err
	}
	// 不得恢复握手已经超时的请求。
	if !w.timer.Stop() {
		connection.Close()
		return nil, nil, context.DeadlineExceeded
	}
	if err = connection.SetDeadline(time.Time{}); err != nil {
		connection.Close()
		return nil, nil, err
	}
	owned := &upgradedConn{Conn: connection, cancel: w.cancel, connections: w.connections}
	w.connections.Store(owned, struct{}{})
	w.release() // WebSocket 计入连接数，不计入活动 HTTP 请求数。
	return owned, buffer, nil
}
func (w *upgradeWriter) Unwrap() http.ResponseWriter { return w.ResponseWriter }
func (w *upgradeWriter) Flush()                      { _ = http.NewResponseController(w.ResponseWriter).Flush() }

type upgradedConn struct {
	net.Conn
	once        sync.Once
	cancel      context.CancelFunc
	connections *sync.Map
}

func (c *upgradedConn) Close() error {
	err := c.Conn.Close()
	c.once.Do(func() { c.cancel(); c.connections.Delete(c) })
	return err
}

func upgradeAwareHandler(next http.Handler, limits map[string]int, connections *sync.Map) http.Handler {
	slots := make(chan struct{}, limits["maxRequests"])
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		defer func() {
			if value := recover(); value != nil && !data.IsRequestCanceled(value) {
				panic(value)
			}
		}()
		select {
		case slots <- struct{}{}:
		default:
			http.Error(w, "Service Unavailable", http.StatusServiceUnavailable)
			return
		}
		var once sync.Once
		release := func() { once.Do(func() { <-slots }) }
		defer release()
		ctx, cancel := context.WithCancel(r.Context())
		defer cancel()
		timer := time.AfterFunc(time.Duration(limits["requestTimeoutMs"])*time.Millisecond, cancel)
		defer timer.Stop()
		r = r.WithContext(ctx)
		if r.ContentLength > int64(limits["maxBodyBytes"]) {
			http.Error(w, "Request Entity Too Large", http.StatusRequestEntityTooLarge)
			return
		}
		body := http.MaxBytesReader(w, r.Body, int64(limits["maxBodyBytes"]))
		defer body.Close()
		payload, err := io.ReadAll(body)
		if err != nil {
			var large *http.MaxBytesError
			if errors.As(err, &large) {
				http.Error(w, "Request Entity Too Large", 413)
			} else {
				http.Error(w, "Cannot read request body", 400)
			}
			return
		}
		r.Body = io.NopCloser(bytes.NewReader(payload))
		next.ServeHTTP(&upgradeWriter{ResponseWriter: w, timer: timer, cancel: cancel, release: release, connections: connections}, r)
	})
}

type serveHTTP struct{}

func (*serveHTTP) GetName() string { return "Loom\\Native\\serveHttp" }
func (*serveHTTP) GetParams() []data.GetValue {
	return []data.GetValue{data.NewParameter("server", 0), data.NewParameter("address", 1), data.NewParameter("limits", 2)}
}
func (*serveHTTP) GetVariables() []data.Variable {
	return []data.Variable{data.NewVariable("server", 0, data.NewBaseType("object")), data.NewVariable("address", 1, data.NewBaseType("string")), data.NewVariable("limits", 2, data.NewBaseType("string"))}
}
func (*serveHTTP) Call(ctx data.Context) (data.GetValue, data.Control) {
	mux, err := utils.ConvertFromIndex[*http.ServeMux](ctx, 0)
	if err != nil {
		return nil, utils.NewThrow(err)
	}
	address, err := utils.ConvertFromIndex[string](ctx, 1)
	if err != nil {
		return nil, utils.NewThrow(err)
	}
	encoded, err := utils.ConvertFromIndex[string](ctx, 2)
	if err != nil {
		return nil, utils.NewThrow(err)
	}
	var limits map[string]int
	if err = json.Unmarshal([]byte(encoded), &limits); err != nil {
		return nil, utils.NewThrow(err)
	}
	for _, key := range []string{"maxConnections", "maxRequests", "maxBodyBytes", "maxHeaderBytes", "readHeaderTimeoutMs", "readTimeoutMs", "writeTimeoutMs", "idleTimeoutMs", "requestTimeoutMs"} {
		if limits[key] < 1 {
			return nil, utils.NewThrowf("Invalid HTTP limit: %s", key)
		}
	}
	connections := new(sync.Map)
	server := &http.Server{
		Addr: address, Handler: upgradeAwareHandler(mux, limits, connections), MaxHeaderBytes: limits["maxHeaderBytes"],
		ReadHeaderTimeout: time.Duration(limits["readHeaderTimeoutMs"]) * time.Millisecond,
		ReadTimeout:       time.Duration(limits["readTimeoutMs"]) * time.Millisecond,
		WriteTimeout:      time.Duration(limits["writeTimeoutMs"]) * time.Millisecond,
		IdleTimeout:       time.Duration(limits["idleTimeoutMs"]) * time.Millisecond,
	}
	shutdown := &httpShutdown{server: server, connections: connections}
	ctx.GetVM().AddShutdownCallback(data.NewFuncValue(shutdown))
	stop := context.AfterFunc(ctx.GoContext(), shutdown.close)
	defer stop()
	defer shutdown.close()
	listener, err := net.Listen("tcp", address)
	if err != nil {
		return nil, utils.NewThrow(err)
	}
	listener = netutil.LimitListener(listener, limits["maxConnections"])
	defer listener.Close()
	if err = server.Serve(listener); err != nil && !errors.Is(err, http.ErrServerClosed) {
		return nil, utils.NewThrow(err)
	}
	return nil, nil
}

type httpShutdown struct {
	server      *http.Server
	connections *sync.Map
	once        sync.Once
}

func (f *httpShutdown) close() {
	f.once.Do(func() {
		f.connections.Range(func(key, _ any) bool { key.(*upgradedConn).Close(); return true })
		_ = f.server.Close()
	})
}
func (f *httpShutdown) Call(data.Context) (data.GetValue, data.Control) { f.close(); return nil, nil }
func (*httpShutdown) GetName() string                                   { return "" }
func (*httpShutdown) GetParams() []data.GetValue                        { return nil }
func (*httpShutdown) GetVariables() []data.Variable                     { return nil }

// 仅推送的脚本仍需要一个读取器来处理对端的关闭和 ping 帧。
// Gorilla 允许一个读取器与脚本的单个写入器并行工作。
type watchWebSocket struct{}

func (*watchWebSocket) GetName() string { return "Loom\\Native\\watchWebSocket" }
func (*watchWebSocket) GetParams() []data.GetValue {
	return []data.GetValue{data.NewParameter("connection", 0)}
}
func (*watchWebSocket) GetVariables() []data.Variable {
	return []data.Variable{data.NewVariable("connection", 0, data.NewBaseType("object"))}
}
func (*watchWebSocket) Call(ctx data.Context) (data.GetValue, data.Control) {
	conn, err := utils.ConvertFromIndex[*websocket.Conn](ctx, 0)
	if err != nil {
		return nil, utils.NewThrow(err)
	}
	go func() {
		defer conn.Close()
		for {
			if _, _, err := conn.ReadMessage(); err != nil {
				return
			}
		}
	}()
	return nil, nil
}
