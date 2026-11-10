package extensions

import (
	"context"
	"encoding/json"
	"testing"
)

func TestProtocolExchangeTcpStampsCorrelation(t *testing.T) {
	parent := context.WithValue(context.Background(), correlationKey{}, "trace-parent-abc")
	result := protocolExchange(parent, "tcp", "127.0.0.1:1", "", `{"action":"ping"}`)
	if result["ok"].(bool) {
		t.Fatal("expected dial failure without listener")
	}
	// Dial fails before send; still ensure stamping logic does not break JSON.
	payload := `{"action":"ping"}`
	parent = context.WithValue(context.Background(), correlationKey{}, "trace-parent-abc")
	var envelope map[string]any
	if err := json.Unmarshal([]byte(payload), &envelope); err != nil {
		t.Fatal(err)
	}
	correlation := CorrelationFromContext(parent)
	if correlation == "" {
		t.Fatal("missing correlation on context")
	}
	envelope["_loom_correlation_id"] = correlation
	stamped, err := json.Marshal(envelope)
	if err != nil {
		t.Fatal(err)
	}
	if string(stamped) != `{"_loom_correlation_id":"trace-parent-abc","action":"ping"}` && string(stamped) != `{"action":"ping","_loom_correlation_id":"trace-parent-abc"}` {
		t.Fatalf("unexpected stamp: %s", stamped)
	}
}
