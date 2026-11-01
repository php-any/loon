package host

import (
	"os"
	"os/exec"
	"path/filepath"
	"strings"
	"testing"
)

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
