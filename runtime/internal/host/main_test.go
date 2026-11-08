package host

import (
	"os"
	"os/exec"
	"path/filepath"
	"strings"
	"testing"
)

func TestProjectRoot(t *testing.T) {
	root := t.TempDir()
	if err := os.WriteFile(filepath.Join(root, "loom.php"), []byte("<?php"), 0644); err != nil {
		t.Fatal(err)
	}
	nested := filepath.Join(root, "framework", "tests")
	if err := os.MkdirAll(nested, 0700); err != nil {
		t.Fatal(err)
	}
	if projectRoot(nested) != root {
		t.Fatalf("project root: %s", projectRoot(nested))
	}
	if projectRoot(t.TempDir()) != "" {
		t.Fatal("script outside a project has no class root")
	}
}

func TestResolveProject(t *testing.T) {
	root := t.TempDir()
	entry := filepath.Join(root, "loom.php")
	if err := os.WriteFile(entry, []byte("<?php"), 0644); err != nil {
		t.Fatal(err)
	}
	for _, tc := range []struct{ name, cwd, exe, override string }{
		{"working directory ancestor", filepath.Join(root, "app"), "elsewhere/loom", ""},
		{"deployment", t.TempDir(), filepath.Join(root, "build", "loom"), ""},
		{"explicit root", t.TempDir(), "elsewhere/loom", root},
	} {
		t.Run(tc.name, func(t *testing.T) {
			path, args, err := resolve([]string{"doctor"}, tc.cwd, tc.exe, tc.override)
			if err != nil || path != entry || len(args) != 1 || args[0] != "doctor" {
				t.Fatalf("resolve: %q %v %v", path, args, err)
			}
		})
	}
}

func TestHostProcess(t *testing.T) {
	if os.Getenv("LOOM_HOST_TEST_CHILD") == "1" {
		for i, arg := range os.Args {
			if arg == "--" {
				os.Exit(Run(os.Args[i+1:]))
			}
		}
		os.Exit(99)
	}
	for _, tc := range []struct {
		name, source, want string
		code               int
	}{
		{"arguments and extensions", "<?php echo $argv[1]; echo '|'; echo LOOM_RUNTIME; echo '|'; echo PHP_BINARY;", "literal $() & argument|loom|", 0},
		{"exit status", "<?php exit(7);", "", 7},
		{"exception", "<?php throw new RuntimeException('host failure');", "host failure", 1},
		{"parse failure", "<?php function (", "", 1},
		{"missing script", "", "", 1},
	} {
		t.Run(tc.name, func(t *testing.T) {
			// tempnam() 生成无扩展名脚本，供进程和工作进程测试使用。
			path := filepath.Join(t.TempDir(), "script with spaces")
			if tc.source != "" {
				if err := os.WriteFile(path, []byte(tc.source), 0644); err != nil {
					t.Fatal(err)
				}
			}
			cmd := exec.Command(os.Args[0], "-test.run=^TestHostProcess$", "--", path, "literal $() & argument")
			cmd.Env = append(os.Environ(), "LOOM_HOST_TEST_CHILD=1")
			out, err := cmd.CombinedOutput()
			code := 0
			if err != nil {
				if exit, ok := err.(*exec.ExitError); ok {
					code = exit.ExitCode()
				} else {
					t.Fatal(err)
				}
			}
			if code != tc.code || !strings.Contains(string(out), tc.want) {
				t.Fatalf("code=%d want=%d output=%s", code, tc.code, out)
			}
		})
	}
}

func TestGenStdUsage(t *testing.T) {
	if generateStd(t.TempDir(), []string{"--bogus"}) != 1 {
		t.Fatal("unknown flag should fail")
	}
	if generateStd("", []string{"-o"}) != 1 {
		t.Fatal("missing output should fail")
	}
}

func TestGenStdCommand(t *testing.T) {
	if os.Getenv("LOOM_GENSTD_CHILD") == "1" {
		for i, arg := range os.Args {
			if arg == "--" {
				os.Exit(Run(os.Args[i+1:]))
			}
		}
		os.Exit(99)
	}
	root := t.TempDir()
	if err := os.WriteFile(filepath.Join(root, "loom.php"), []byte("<?php echo 'should-not-run';"), 0644); err != nil {
		t.Fatal(err)
	}
	cmd := exec.Command(os.Args[0], "-test.run=^TestGenStdCommand$", "--", "gen-std")
	cmd.Dir = root
	cmd.Env = append(os.Environ(), "LOOM_GENSTD_CHILD=1")
	out, err := cmd.CombinedOutput()
	if err != nil {
		t.Fatalf("gen-std: %v\n%s", err, out)
	}
	text := string(out)
	if strings.Contains(text, "should-not-run") {
		t.Fatalf("gen-std executed the project script:\n%s", text)
	}
	for _, rel := range []string{
		filepath.Join(".zy", "std", "functions.php"),
		filepath.Join(".zy", "std", "Loom", "Native", "servehttp.php"),
		filepath.Join(".zy", "std", "Net", "Grpc", "server.php"),
		filepath.Join(".zy", "std", "Illuminate", "Support", "str.php"),
	} {
		body, err := os.ReadFile(filepath.Join(root, rel))
		if err != nil {
			t.Fatalf("missing %s: %v\n%s", rel, err, text)
		}
		if !strings.Contains(string(body), "<?php") {
			t.Fatalf("%s is not a php stub", rel)
		}
	}
	native, err := os.ReadFile(filepath.Join(root, ".zy", "std", "Loom", "Native", "servehttp.php"))
	if err != nil {
		t.Fatal(err)
	}
	if !strings.Contains(string(native), "function serveHttp") {
		t.Fatalf("native stub:\n%s", native)
	}
	globals, err := os.ReadFile(filepath.Join(root, ".zy", "std", "functions.php"))
	if err != nil {
		t.Fatal(err)
	}
	globalText := string(globals)
	if !strings.Contains(globalText, "function dump") || strings.Contains(globalText, "function array(") {
		t.Fatal("global stubs should keep dump() and leave reserved names in their own files")
	}
}
