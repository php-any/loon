package loom

import (
	"context"
	_ "embed"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"log"
	"net/http"
	"os"
	"path/filepath"
	"strings"
	"time"
	"unicode/utf8"

	"github.com/php-any/origami/data"
	"github.com/php-any/origami/parser"
	"github.com/php-any/origami/runtime"
	"github.com/php-any/origami/std"
	nethttp "github.com/php-any/origami/std/net/http"
	"github.com/php-any/origami/std/net/websocket"
	"github.com/php-any/origami/std/php"
)

//go:embed php/loom.php
var frameworkSource string

//go:embed php/services.php
var servicesSource string

//go:embed php/orm.php
var ormSource string

type Config struct {
	Services *Services
	// ResolveServices 为非 HTTP 宿主提供延迟初始化资源的能力。
	// 调用方负责初始化同步以及已创建服务的关闭。
	ResolveServices       func() (*Services, error)
	Entry                 string
	Timeout               time.Duration
	MaxBodyBytes          int64
	MaxResponseBytes      int
	MaxConcurrentRequests int
	// WebSocketTimeout bounds the entire connection lifetime, including PHP execution.
	WebSocketTimeout         time.Duration
	MaxWebSocketConnections  int
	MaxWebSocketMessageBytes int64
	// OnError receives internal details; HTTP clients receive a generic problem.
	// It may be called concurrently and must not panic.
	OnError func(error)
}

type Handler struct {
	config         Config
	base           *runtime.VM
	parser         *parser.Parser
	slots          chan struct{}
	websocketSlots chan struct{}
}

// NewHandler builds an immutable host. Application declarations and objects are
// loaded into each RequestVM; no PHP application object is shared across requests.
func NewHandler(config Config) (*Handler, error) {
	entry, err := filepath.Abs(config.Entry)
	if err != nil {
		return nil, err
	}
	info, err := os.Stat(entry)
	if err != nil {
		return nil, err
	}
	if !info.Mode().IsRegular() {
		return nil, fmt.Errorf("Loom entry must be a regular file")
	}
	config.Entry = entry
	if config.WebSocketTimeout == 0 {
		config.WebSocketTimeout = time.Hour
	}
	if config.MaxWebSocketConnections == 0 {
		config.MaxWebSocketConnections = 64
	}
	if config.MaxWebSocketMessageBytes == 0 {
		config.MaxWebSocketMessageBytes = 1 << 20
	}
	if config.WebSocketTimeout < 0 || config.MaxWebSocketConnections < 0 || config.MaxWebSocketMessageBytes < 0 {
		return nil, fmt.Errorf("Loom WebSocket limits must be positive")
	}
	if config.Timeout == 0 {
		config.Timeout = 10 * time.Second
	}
	if config.MaxBodyBytes == 0 {
		config.MaxBodyBytes = 1 << 20
	}
	if config.MaxResponseBytes == 0 {
		config.MaxResponseBytes = 4 << 20
	}
	if config.MaxConcurrentRequests == 0 {
		config.MaxConcurrentRequests = 64
	}
	if config.Timeout < 0 || config.MaxBodyBytes < 0 || config.MaxResponseBytes < 0 || config.MaxConcurrentRequests < 0 {
		return nil, fmt.Errorf("Loom limits must be positive")
	}
	if config.OnError == nil {
		config.OnError = func(err error) { log.Printf("loom: %v", err) }
	}
	p := parser.NewParser()
	vm := runtime.NewVM(p).(*runtime.VM)
	std.Load(vm)
	php.Load(vm)
	Load(vm)
	vm.AddClass(nethttp.NewRequestClass())
	vm.AddClass(nethttp.NewResponseWriterClass())
	websocket.Load(vm)
	for _, op := range []string{"request", "response", "configure"} {
		vm.AddFunc(&websocketFunction{op: op})
	}
	vm.AddFunc(&serviceFunction{services: config.Services, resolve: config.ResolveServices})
	vm.AddFunc(&consoleFunction{})
	return &Handler{config: config, base: vm, parser: p, slots: make(chan struct{}, config.MaxConcurrentRequests), websocketSlots: make(chan struct{}, config.MaxWebSocketConnections)}, nil
}

type responseSink interface {
	Header() http.Header
	WriteHeader(int)
	Write([]byte) (int, error)
}

func (h *Handler) ServeHTTP(w http.ResponseWriter, r *http.Request) {
	// HEAD has the same representation headers/status, but never a response body.
	sink := &headSink{ResponseWriter: w, head: r.Method == http.MethodHead}
	slots, timeout := h.slots, h.config.Timeout
	var upgrade *websocketScope
	if isWebSocketRequest(r) {
		slots, timeout = h.websocketSlots, h.config.WebSocketTimeout
		upgrade = &websocketScope{ResponseWriter: w, config: h.config}
		defer upgrade.close()
	}
	select {
	case slots <- struct{}{}:
		defer func() { <-slots }()
	default:
		problem(sink, 503, "Server is busy")
		return
	}
	ctx, cancel := context.WithTimeout(r.Context(), timeout)
	defer cancel()
	if upgrade != nil {
		ctx = context.WithValue(ctx, websocketKey{}, upgrade)
	}
	r = r.WithContext(ctx)
	body, err := io.ReadAll(http.MaxBytesReader(w, r.Body, h.config.MaxBodyBytes))
	if ctx.Err() != nil {
		problem(sink, 504, "Execution deadline exceeded")
		return
	}
	if err != nil {
		var tooLarge *http.MaxBytesError
		if errors.As(err, &tooLarge) {
			problem(sink, 413, "Request body too large")
		} else {
			problem(sink, 400, "Cannot read request body")
		}
		return
	}
	if !utf8.Valid(body) {
		problem(sink, 400, "Request body must be UTF-8")
		return
	}
	headers := make(map[string][]string, len(r.Header))
	for k, v := range r.Header {
		headers[strings.ToLower(k)] = v
	}
	encoded, err := json.Marshal(requestEnvelope{r.Method, r.URL.Path, r.URL.Query(), headers, string(body)})
	if err != nil {
		h.fail(sink, err)
		return
	}
	r = r.WithContext(context.WithValue(ctx, requestKey{}, string(encoded)))
	response, err := h.execute(r)
	if upgrade != nil && upgrade.committed {
		if err != nil && ctx.Err() == nil {
			h.config.OnError(err)
		}
		return
	}
	if ctx.Err() != nil {
		problem(sink, 504, "Execution deadline exceeded")
		return
	}
	if err != nil {
		h.fail(sink, err)
		return
	}
	for k, v := range response.Headers {
		sink.Header().Set(k, v)
	}
	sink.Header().Set("X-Content-Type-Options", "nosniff")
	sink.WriteHeader(response.Status)
	if response.Status != 204 && response.Status != 304 {
		_, _ = sink.Write([]byte(response.Body))
	}
}

func (h *Handler) fail(w responseSink, err error) {
	h.config.OnError(err)
	problem(w, 500, "Internal Server Error")
}

// execute buffers the entire result and cleanup before committing HTTP headers.
// Cancellation is cooperative: this function never abandons a live PHP goroutine.
func (h *Handler) execute(r *http.Request) (response responseEnvelope, err error) {
	defer func() {
		if failure := recover(); failure != nil {
			err = fmt.Errorf("PHP execution failed: %v", failure)
		}
	}()
	vm := runtime.NewRequestVM(h.base).(*runtime.RequestVM)
	restore := vm.Activate()
	defer restore()
	restoreDeadline := runtime.BeginRequestDeadline(r.Context())
	defer restoreDeadline()
	discard := &captureWriter{header: make(http.Header)}
	vm.BindHTTP(r, discard)
	vm.SetOutputWriter(func(s string) { discard.wrote = discard.wrote || len(s) != 0 })
	defer func() {
		vm.RunShutdownCallbacks()
		if vm.OutputBufferLevel() != 0 {
			err = fmt.Errorf("Loom actions must close their output buffers before returning")
		}
		if control := vm.TakeThrow(); control != nil {
			err = fmt.Errorf("shutdown: %s", control.AsString())
		}
		if discard.wrote || len(discard.header) != 0 {
			err = fmt.Errorf("Loom actions must return a Response; direct output or headers are not allowed")
		}
	}()
	p := vm.PrepareParse(h.parser)
	program, control := p.ParseString(frameworkSource, "loom://framework/loom.php")
	if control != nil {
		return response, fmt.Errorf("framework parse: %s", control.AsString())
	}
	if _, control = program.GetValue(vm.CreateContext(p.GetVariables())); control != nil {
		return response, fmt.Errorf("framework initialization: %s", control.AsString())
	}
	program, control = p.ParseString(servicesSource, "loom://framework/services.php")
	if control != nil {
		return response, fmt.Errorf("services parse: %s", control.AsString())
	}
	if _, control = program.GetValue(vm.CreateContext(p.GetVariables())); control != nil {
		return response, fmt.Errorf("services initialization: %s", control.AsString())
	}
	program, control = p.ParseString(ormSource, "loom://framework/orm.php")
	if control != nil {
		return response, fmt.Errorf("ORM parse: %s", control.AsString())
	}
	if _, control = program.GetValue(vm.CreateContext(p.GetVariables())); control != nil {
		return response, fmt.Errorf("ORM initialization: %s", control.AsString())
	}
	result, control := vm.LoadAndRun(h.config.Entry)
	if control != nil {
		return response, fmt.Errorf("application: %s", control.AsString())
	}
	value, ok := result.(data.Value)
	if !ok {
		return response, fmt.Errorf("application must return a response envelope")
	}
	encoded, valid, control := php.JsonEncodeFlags(vm.CreateContext(nil), value, 4194304) // JSON_THROW_ON_ERROR
	if control != nil {
		return response, fmt.Errorf("response serialization: %s", control.AsString())
	}
	if !valid {
		return response, fmt.Errorf("response cannot be JSON encoded")
	}
	decoder := json.NewDecoder(strings.NewReader(encoded))
	decoder.DisallowUnknownFields()
	var wire struct {
		Status  *int               `json:"status"`
		Headers *map[string]string `json:"headers"`
		Body    *string            `json:"body"`
	}
	if err = decoder.Decode(&wire); err != nil {
		return response, fmt.Errorf("invalid response envelope: %w", err)
	}
	if wire.Status == nil || wire.Headers == nil || wire.Body == nil {
		return response, fmt.Errorf("response requires status, headers and body")
	}
	response = responseEnvelope{Status: *wire.Status, Headers: *wire.Headers, Body: *wire.Body}
	if response.Status < 200 || response.Status > 599 {
		return response, fmt.Errorf("invalid response status")
	}
	if len(response.Body) > h.config.MaxResponseBytes {
		return response, fmt.Errorf("response body exceeds limit")
	}
	if (response.Status == 204 || response.Status == 304) && response.Body != "" {
		return response, fmt.Errorf("body forbidden for status %d", response.Status)
	}
	seen := make(map[string]bool, len(response.Headers))
	for k, v := range response.Headers {
		if !validHeaderName(k) || !validHeaderValue(v) {
			return response, fmt.Errorf("invalid response header")
		}
		canonical := strings.ToLower(k)
		if seen[canonical] {
			return response, fmt.Errorf("duplicate response header: %s", k)
		}
		seen[canonical] = true
		switch strings.ToLower(k) {
		case "connection", "content-length", "transfer-encoding", "trailer", "upgrade", "keep-alive", "proxy-connection":
			return response, fmt.Errorf("host-controlled response header: %s", k)
		}
	}
	return response, nil
}

func validHeaderValue(s string) bool {
	for i := 0; i < len(s); i++ {
		if s[i] == 127 || s[i] < 32 && s[i] != '\t' {
			return false
		}
	}
	return true
}

func validHeaderName(s string) bool {
	if s == "" {
		return false
	}
	for _, c := range s {
		if c >= 'a' && c <= 'z' || c >= 'A' && c <= 'Z' || c >= '0' && c <= '9' || strings.ContainsRune("!#$%&'*+-.^_`|~", c) {
			continue
		}
		return false
	}
	return true
}

type captureWriter struct {
	header http.Header
	wrote  bool
}

func (w *captureWriter) Header() http.Header         { return w.header }
func (w *captureWriter) WriteHeader(int)             { w.wrote = true }
func (w *captureWriter) Write(b []byte) (int, error) { w.wrote = true; return len(b), nil }

type headSink struct {
	http.ResponseWriter
	head bool
}

func (w *headSink) Write(b []byte) (int, error) {
	if w.head {
		return len(b), nil
	}
	return w.ResponseWriter.Write(b)
}
