package loom

import (
	"bytes"
	"context"
	"errors"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"
)

func testConsole(t *testing.T, source string, limit int) *ConsoleRunner {
	t.Helper()
	path := filepath.Join(t.TempDir(), "console.php")
	if err := os.WriteFile(path, []byte("<?php\n"+source), 0600); err != nil {
		t.Fatal(err)
	}
	runner, err := NewConsole(Config{Entry: path, MaxResponseBytes: limit})
	if err != nil {
		t.Fatal(err)
	}
	return runner
}

func TestConsoleStreamsExitAndIsolation(t *testing.T) {
	runner := testConsole(t, `class Counter { public static int $value = 0; }
 $console = new \Loom\Console\Console();
 $console->command('hello {name} {--E|error}', function($in, $out) {
 Counter::$value++; $out->line($in->argument('name') . ':' . Counter::$value);
 if ($in->option('error')) { $out->error('diagnostic'); } return 7;
 }); return $console->run();`, 0)
	for i := 0; i < 2; i++ {
		var out, errOut bytes.Buffer
		code, err := runner.Run(context.Background(), []string{"hello", "世界", "-E"}, &out, &errOut)
		if err != nil || code != 7 || out.String() != "世界:1\n" || errOut.String() != "diagnostic\n" {
			t.Fatalf("code=%d error=%v stdout=%q stderr=%q", code, err, out.String(), errOut.String())
		}
	}
}

func TestConsoleOutputLimitAndCancellation(t *testing.T) {
	runner := testConsole(t, `echo str_repeat('x', 100); return 0;`, 16)
	var out bytes.Buffer
	code, err := runner.Run(context.Background(), nil, &out, nil)
	if code != 1 || err == nil || !strings.Contains(err.Error(), "命令输出超过大小限制") || out.Len() > 16 {
		t.Fatalf("%d %v %q", code, err, out.String())
	}
	runner = testConsole(t, `while (true) {} return 0;`, 0)
	ctx, cancel := context.WithTimeout(context.Background(), 30*time.Millisecond)
	defer cancel()
	code, err = runner.Run(ctx, nil, nil, nil)
	if code != 1 || !errors.Is(err, context.DeadlineExceeded) {
		t.Fatalf("%d %v", code, err)
	}
}
