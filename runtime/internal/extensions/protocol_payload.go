package extensions

import (
	"encoding/json"
	"strings"
)

const correlationPayloadKey = "_loom_correlation_id"

// stampCorrelationPayload merges correlation into JSON object payloads. For gRPC StringValue
// wires that encode a JSON object inside a JSON string, the outer quoted form is preserved.
func stampCorrelationPayload(payload, correlation string) string {
	if correlation == "" {
		return payload
	}
	if stamped, ok := stampJSONObject(payload, correlation); ok && stamped != payload {
		return stamped
	}
	var quoted string
	if err := json.Unmarshal([]byte(payload), &quoted); err != nil {
		return payload
	}
	inner, ok := stampJSONObject(quoted, correlation)
	if !ok || inner == quoted {
		return payload
	}
	out, err := json.Marshal(inner)
	if err != nil {
		return payload
	}
	return string(out)
}

func stampJSONObject(payload, correlation string) (string, bool) {
	trimmed := strings.TrimSpace(payload)
	if !strings.HasPrefix(trimmed, "{") {
		return payload, false
	}
	var envelope map[string]any
	if err := json.Unmarshal([]byte(trimmed), &envelope); err != nil || envelope == nil {
		return payload, false
	}
	if _, exists := envelope[correlationPayloadKey]; exists {
		return payload, true
	}
	envelope[correlationPayloadKey] = correlation
	stamped, err := json.Marshal(envelope)
	if err != nil {
		return payload, false
	}
	return string(stamped), true
}
