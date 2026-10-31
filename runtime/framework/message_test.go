package loom

import (
	"context"
	"errors"
	"strings"
	"sync"
	"testing"
)

func TestMessageIsolationAndBoundaries(t *testing.T) {
	h := fixture(t, `$input = json_decode(\Loom\message_envelope(), true); $GLOBALS['count'] = ($GLOBALS['count'] ?? 0) + 1; return ['name' => $input['name'], 'count' => $GLOBALS['count']];`, nil)
	var workers sync.WaitGroup
	for i := 0; i < 8; i++ {
		workers.Add(1)
		go func() {
			defer workers.Done()
			result, err := h.RunMessage(context.Background(), []byte(`{"name":"Ada"}`))
			if err != nil || !strings.Contains(string(result), `"count":1`) {
				t.Errorf("result=%s err=%v", result, err)
			}
		}()
	}
	workers.Wait()
	busy := fixture(t, `return [];`, func(c *Config) { c.MaxConcurrentRequests = 1 })
	busy.slots <- struct{}{}
	if _, err := busy.RunMessage(context.Background(), []byte(`{}`)); !errors.Is(err, ErrMessageBusy) {
		t.Fatalf("busy: %v", err)
	}
	<-busy.slots
	for _, source := range []string{`echo 'leak'; return [];`, `throw new \RuntimeException('private');`} {
		bad := fixture(t, source, nil)
		if _, err := bad.RunMessage(context.Background(), []byte(`{}`)); err == nil {
			t.Fatal("expected execution error")
		}
	}
	limited := fixture(t, `return ['long' => '1234567890'];`, func(c *Config) { c.MaxBodyBytes = 5; c.MaxResponseBytes = 5 })
	if _, err := limited.RunMessage(context.Background(), []byte(`{"name":"Ada"}`)); err == nil {
		t.Fatal("input limit not enforced")
	}
	if _, err := limited.RunMessage(context.Background(), []byte(`{}`)); err == nil {
		t.Fatal("output limit not enforced")
	}
	ctx, cancel := context.WithCancel(context.Background())
	cancel()
	if _, err := h.RunMessage(ctx, []byte(`{}`)); err == nil {
		t.Fatal("cancellation not propagated")
	}
}
