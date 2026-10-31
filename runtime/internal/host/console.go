package host

import (
	"context"
	"errors"
	"fmt"
	"io"
	"os"
	"os/signal"
	"path/filepath"
	"sync"
	"syscall"

	"github.com/php-any/loom/framework"
)

func runApplicationConsole(entry, database string, args []string, stdout, stderr io.Writer) int {
	ctx, stop := signal.NotifyContext(context.Background(), syscall.SIGINT, syscall.SIGTERM)
	defer stop()
	return executeConsole(ctx, entry, database, args, stdout, stderr)
}
func executeConsole(ctx context.Context, entry, database string, args []string, stdout, stderr io.Writer) int {
	var once sync.Once
	var services *loom.Services
	var initErr error
	defer func() {
		if services != nil {
			services.Close()
		}
	}()
	resolve := func() (*loom.Services, error) {
		once.Do(func() {
			if initErr = os.MkdirAll(filepath.Dir(database), 0700); initErr == nil {
				services, initErr = openStudio(database)
			}
		})
		return services, initErr
	}
	runner, err := loom.NewConsole(loom.Config{Entry: entry, ResolveServices: resolve})
	if err != nil {
		fmt.Fprintln(stderr, err)
		return 1
	}
	code, err := runner.Run(ctx, args, stdout, stderr)
	if err != nil {
		fmt.Fprintln(stderr, err)
		if errors.Is(err, context.Canceled) {
			return 130
		}
		if errors.Is(err, context.DeadlineExceeded) {
			return 124
		}
		return 1
	}
	return code
}
