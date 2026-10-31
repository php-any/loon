// Package loom hosts isolated PHP applications without loading framework bridges.
package loom

import (
	"encoding/json"
	"fmt"

	"github.com/php-any/origami/data"
	"github.com/php-any/origami/runtime"
)

// Load registers only Loom's host boundary. Load the PHP standard library first.
func Load(vm data.VM) { vm.AddFunc(&requestFunction{}); vm.AddFunc(&messageFunction{}) }

type requestFunction struct{}

func (*requestFunction) GetName() string               { return "Loom\\request_envelope" }
func (*requestFunction) GetParams() []data.GetValue    { return nil }
func (*requestFunction) GetVariables() []data.Variable { return nil }
func (*requestFunction) Call(ctx data.Context) (data.GetValue, data.Control) {
	vm, ok := ctx.GetVM().(*runtime.RequestVM)
	if !ok || vm.HTTPRequest() == nil {
		return nil, data.NewErrorThrow(nil, fmt.Errorf("Loom request requires an HTTP execution scope"))
	}
	envelope, ok := vm.HTTPRequest().Context().Value(requestKey{}).(string)
	if !ok {
		return nil, data.NewErrorThrow(nil, fmt.Errorf("Loom request envelope is missing"))
	}
	return data.NewStringValue(envelope), nil
}

type requestKey struct{}

type requestEnvelope struct {
	Method  string              `json:"method"`
	Path    string              `json:"path"`
	Query   map[string][]string `json:"query"`
	Headers map[string][]string `json:"headers"`
	Body    string              `json:"body"`
}

type responseEnvelope struct {
	Status  int               `json:"status"`
	Headers map[string]string `json:"headers"`
	Body    string            `json:"body"`
}

func problem(w responseSink, status int, title string) {
	body, _ := json.Marshal(map[string]any{"type": "about:blank", "title": title, "status": status})
	w.Header().Set("Content-Type", "application/problem+json; charset=utf-8")
	w.Header().Set("X-Content-Type-Options", "nosniff")
	w.WriteHeader(status)
	_, _ = w.Write(body)
}
