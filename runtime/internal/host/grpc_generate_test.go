package host

import (
	"bytes"
	"context"
	"os"
	"path/filepath"
	"strings"
	"testing"

	"github.com/php-any/loom/framework"
)

func TestGRPCGeneratorPreservesBusinessAndUpdatesMethods(t *testing.T) {
	root := t.TempDir()
	if err := os.MkdirAll(filepath.Join(root, "proto"), 0755); err != nil {
		t.Fatal(err)
	}
	source := `syntax = "proto3"; package demo; message Request { int32 id = 1; } message Reply { int32 id = 1; } service Users { rpc Get(Request) returns (Reply); }`
	protoPath := filepath.Join(root, "proto/user.proto")
	if err := os.WriteFile(protoPath, []byte(source), 0600); err != nil {
		t.Fatal(err)
	}
	config := &protocolConfig{ImportPaths: []string{"proto"}}
	var output bytes.Buffer
	if err := generateGRPC(context.Background(), root, "proto/user.proto", config, &output); err != nil {
		t.Fatal(err)
	}
	business := filepath.Join(root, "app/Grpc/Services/Demo/Users.php")
	original, err := os.ReadFile(business)
	if err != nil {
		t.Fatal(err)
	}
	custom := strings.Replace(string(original), `throw new \Loom\Grpc\RpcException('unimplemented', 'Implement get in Users');`, `return ['id' => $request['id']];`, 1)
	if custom == string(original) {
		t.Fatal("missing generated stub")
	}
	if err := os.WriteFile(business, []byte(custom), 0600); err != nil {
		t.Fatal(err)
	}
	source = strings.Replace(source, "rpc Get(Request) returns (Reply);", "rpc Get(Request) returns (Reply); rpc Added(Request) returns (Reply);", 1)
	if err := os.WriteFile(protoPath, []byte(source), 0600); err != nil {
		t.Fatal(err)
	}
	if err := generateGRPC(context.Background(), root, "proto/user.proto", config, &output); err != nil {
		t.Fatal(err)
	}
	after, _ := os.ReadFile(business)
	if string(after) != custom {
		t.Fatal("business implementation was overwritten")
	}
	base := filepath.Join(root, "app/Grpc/Generated/Demo/UsersBase.php")
	baseSource, _ := os.ReadFile(base)
	if !strings.Contains(string(baseSource), "function added(") {
		t.Fatal("new RPC stub missing")
	}
	manifest, err := readGRPCManifest(root)
	if err != nil {
		t.Fatal(err)
	}
	if len(manifest.Files["proto/user.proto"].Routes) != 2 {
		t.Fatal("registration not updated")
	}
	// Execute generated classes through the real PHP runtime and dispatcher.
	entry := filepath.Join(root, "entry.php")
	php := "<?php\nrequire '" + filepath.ToSlash(base) + "';\nrequire '" + filepath.ToSlash(business) + "';\nreturn \\Loom\\Grpc\\Dispatcher::handle([], '" + filepath.ToSlash(filepath.Join(root, "config/grpc.generated.json")) + "');"
	if err := os.WriteFile(entry, []byte(php), 0600); err != nil {
		t.Fatal(err)
	}
	handler, err := loom.NewHandler(loom.Config{Entry: entry})
	if err != nil {
		t.Fatal(err)
	}
	for _, tc := range []struct{ method, want string }{{"Get", `"data":{"id":7}`}, {"Added", `"code":"unimplemented"`}} {
		result, err := handler.RunMessage(context.Background(), []byte(`{"method":"/demo.Users/`+tc.method+`","request":{"id":7}}`))
		if err != nil || !strings.Contains(string(result), tc.want) {
			t.Fatalf("%s => %s %v", tc.method, result, err)
		}
	}
}

func TestGRPCGeneratorRejectsInvalidInputsWithoutArtifacts(t *testing.T) {
	for _, source := range []string{
		`syntax = "proto3"; message {`,
		`syntax = "proto3"; message M {} service Class { rpc Call(M) returns (M); }`,
		`syntax = "proto3"; message M {} service S { rpc Get(M) returns (M); rpc get(M) returns (M); }`,
	} {
		root := t.TempDir()
		os.MkdirAll(filepath.Join(root, "proto"), 0755)
		os.WriteFile(filepath.Join(root, "proto/bad.proto"), []byte(source), 0600)
		err := generateGRPC(context.Background(), root, "proto/bad.proto", &protocolConfig{ImportPaths: []string{"proto"}}, &bytes.Buffer{})
		if err == nil {
			t.Fatal("invalid contract accepted")
		}
		if _, err := os.Stat(filepath.Join(root, "app")); !os.IsNotExist(err) {
			t.Fatal("invalid input created artifacts")
		}
	}
	if _, err := checkedPath(t.TempDir(), "../escape.php"); err == nil {
		t.Fatal("accepted traversal")
	}
}
