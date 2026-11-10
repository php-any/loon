package extensions

import (
	"encoding/json"
	"strings"
	"testing"
)

func TestStampCorrelationPayloadObject(t *testing.T) {
	in := `{"action":"ping"}`
	out := stampCorrelationPayload(in, "trace-tcp")
	var envelope map[string]any
	if err := json.Unmarshal([]byte(out), &envelope); err != nil {
		t.Fatal(err)
	}
	if envelope["_loom_correlation_id"] != "trace-tcp" || envelope["action"] != "ping" {
		t.Fatalf("unexpected %v", envelope)
	}
}

func TestStampCorrelationPayloadGrpcStringWire(t *testing.T) {
	in := `"{\"total\":1}"`
	out := stampCorrelationPayload(in, "trace-grpc")
	var quoted string
	if err := json.Unmarshal([]byte(out), &quoted); err != nil {
		t.Fatal(err)
	}
	var inner map[string]any
	if err := json.Unmarshal([]byte(quoted), &inner); err != nil {
		t.Fatal(err)
	}
	if inner["_loom_correlation_id"] != "trace-grpc" || inner["total"] != float64(1) {
		t.Fatalf("unexpected inner %v", inner)
	}
}

func TestStampCorrelationPayloadPreservesExisting(t *testing.T) {
	in := `{"_loom_correlation_id":"client-set","action":"ping"}`
	out := stampCorrelationPayload(in, "other")
	if out != in {
		t.Fatalf("should not replace existing: %s", out)
	}
}
