package protocol

import (
	"context"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

func TestSchemaImportsAndMessages(t *testing.T) {
	schema, err := Load(context.Background(), []string{"../../proto"}, []string{"greeter.proto"})
	if err != nil {
		t.Fatal(err)
	}
	if _, err := schema.Files.FindFileByPath("common/greeting.proto"); err != nil {
		t.Fatal(err)
	}
	if _, err := schema.Files.FindFileByPath("google/protobuf/wrappers.proto"); err != nil {
		t.Fatal(err)
	}
	method, err := schema.Method("loom.examples.Greeter", "Greet")
	if err != nil {
		t.Fatal(err)
	}
	for _, payload := range []string{`{"name":"Ada","times":2}`, `{"name":"Ada"}`} {
		if _, err := NewRequest(method, []byte(payload)); err != nil {
			t.Fatal(err)
		}
	}
	for _, payload := range []string{`{"name":"Ada","unknown":true}`, `{"times":"bad"}`, `{`} {
		if _, err := NewRequest(method, []byte(payload)); err == nil {
			t.Fatalf("accepted %s", payload)
		}
	}
}

func TestSchemaFailures(t *testing.T) {
	for _, tc := range []struct{ name, source, want string }{
		{"syntax", `syntax = "proto3"; message {`, "compile proto"},
		{"import", `syntax = "proto3"; import "missing.proto";`, "missing.proto"},
		{"type", `syntax = "proto3"; service S { rpc M(Missing) returns (Missing); }`, "Missing"},
		{"stream", `syntax = "proto3"; message M {} service S { rpc Call(stream M) returns (M); }`, "streaming RPC"},
		{"empty", `syntax = "proto3"; message M {}`, "no services"},
	} {
		t.Run(tc.name, func(t *testing.T) {
			root := t.TempDir()
			if err := os.WriteFile(filepath.Join(root, "test.proto"), []byte(tc.source), 0600); err != nil {
				t.Fatal(err)
			}
			_, err := Load(context.Background(), []string{root}, []string{"test.proto"})
			if err == nil || !strings.Contains(err.Error(), tc.want) {
				t.Fatalf("%v", err)
			}
		})
	}
}
