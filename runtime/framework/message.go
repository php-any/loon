package loom

import (
	"context"
	_ "embed"
	"encoding/json"
	"errors"
	"fmt"
	"net/http"

	"github.com/php-any/origami/data"
	"github.com/php-any/origami/runtime"
	"github.com/php-any/origami/std/php"
)

// ErrMessageBusy indicates that the configured concurrent execution limit was reached.
var ErrMessageBusy = errors.New("message server busy")

//go:embed php/grpc.php
var grpcSource string

// RunMessage executes one protocol message in a fresh PHP scope. The HTTP
// binding is an internal VM context carrier, not an HTTP request or dispatch.
func (h *Handler) RunMessage(ctx context.Context, payload []byte) (out []byte, err error) {
	if err := ctx.Err(); err != nil {
		return nil, err
	}
	if len(payload) > int(h.config.MaxBodyBytes) || !json.Valid(payload) {
		return nil, fmt.Errorf("invalid message")
	}
	select {
	case h.slots <- struct{}{}:
		defer func() { <-h.slots }()
	default:
		return nil, ErrMessageBusy
	}
	ctx, cancel := context.WithTimeout(ctx, h.config.Timeout)
	defer cancel()
	ctx = context.WithValue(ctx, messageKey{}, string(payload))
	req, _ := http.NewRequestWithContext(ctx, "MESSAGE", "http://loom.internal/", nil)
	vm := runtime.NewRequestVM(h.base).(*runtime.RequestVM)
	restore := vm.Activate()
	defer restore()
	deadline := runtime.BeginRequestDeadline(ctx)
	defer deadline()
	discard := &captureWriter{header: make(http.Header)}
	vm.BindHTTP(req, discard)
	vm.SetOutputWriter(func(s string) { discard.wrote = discard.wrote || len(s) > 0 })
	defer func() {
		if p := recover(); p != nil {
			err = fmt.Errorf("message execution: %v", p)
		}
		if ctx.Err() != nil {
			err = ctx.Err()
		}
	}()
	defer func() {
		vm.RunShutdownCallbacks()
		if thrown := vm.TakeThrow(); thrown != nil {
			err = fmt.Errorf("message shutdown: %s", thrown.AsString())
		}
		if discard.wrote || len(discard.header) > 0 || vm.OutputBufferLevel() != 0 {
			err = fmt.Errorf("message handlers must return data without output or headers")
		}
	}()
	p := vm.PrepareParse(h.parser)
	for _, source := range []struct{ name, code string }{{"loom", frameworkSource}, {"services", servicesSource}, {"orm", ormSource}, {"grpc", grpcSource}} {
		program, control := p.ParseString(source.code, "loom://framework/"+source.name+".php")
		if control != nil {
			return nil, fmt.Errorf("message framework: %s", control.AsString())
		}
		if _, control = program.GetValue(vm.CreateContext(p.GetVariables())); control != nil {
			return nil, fmt.Errorf("message framework: %s", control.AsString())
		}
	}
	result, control := vm.LoadAndRun(h.config.Entry)
	if control != nil {
		return nil, fmt.Errorf("message application: %s", control.AsString())
	}
	value, ok := result.(data.Value)
	if !ok {
		return nil, fmt.Errorf("message handler must return data")
	}
	encoded, valid, control := php.JsonEncodeFlags(vm.CreateContext(nil), value, 4194304)
	if control != nil || !valid {
		return nil, fmt.Errorf("message serialization failed")
	}
	if len(encoded) > h.config.MaxResponseBytes {
		return nil, fmt.Errorf("message response exceeds limit")
	}
	return []byte(encoded), nil
}

type messageKey struct{}
type messageFunction struct{}

func (*messageFunction) GetName() string               { return "Loom\\message_envelope" }
func (*messageFunction) GetParams() []data.GetValue    { return nil }
func (*messageFunction) GetVariables() []data.Variable { return nil }
func (*messageFunction) Call(ctx data.Context) (data.GetValue, data.Control) {
	if vm, ok := ctx.GetVM().(*runtime.RequestVM); ok && vm.HTTPRequest() != nil {
		if payload, ok := vm.HTTPRequest().Context().Value(messageKey{}).(string); ok {
			return data.NewStringValue(payload), nil
		}
	}
	return nil, data.NewErrorThrow(nil, fmt.Errorf("message envelope requires a message scope"))
}
