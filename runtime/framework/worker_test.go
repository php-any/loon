package loom

import (
	"context"
	"errors"
	"net/http/httptest"
	"path/filepath"
	"sync"
	"sync/atomic"
	"testing"
)

func TestDurableQueueRecoveryAndTransactions(t *testing.T) {
	s, err := OpenServices(filepath.Join(t.TempDir(), "queue.sqlite"))
	if err != nil {
		t.Fatal(err)
	}
	defer s.Close()
	ctx := context.Background()
	r := httptest.NewRequest("POST", "http://test/", nil)
	if _, err = s.DB.Exec("CREATE TABLE ledger(id INTEGER PRIMARY KEY, value TEXT NOT NULL)"); err != nil {
		t.Fatal(err)
	}
	_, err = s.call(r, command{Op: "db.batch", Statements: []command{{SQL: "INSERT INTO ledger VALUES(1,'ok')"}, {SQL: "INSERT INTO ledger VALUES(1,'duplicate')"}}})
	if err == nil {
		t.Fatal("transaction should fail")
	}
	var count int
	s.DB.QueryRow("SELECT COUNT(*) FROM ledger").Scan(&count)
	if count != 0 {
		t.Fatal("partial transaction committed")
	}
	if _, err = s.call(r, command{Op: "queue.dispatch", Kind: "fail", Owner: 1}); err != nil {
		t.Fatal(err)
	}
	fail := func(context.Context, string) (string, error) { return "", errors.New("expected failure") }
	for i := 1; i <= 3; i++ {
		if err = s.Tick(ctx, fail); err != nil {
			t.Fatal(err)
		}
		var state string
		var attempts int
		if err = s.DB.QueryRow("SELECT state,attempts FROM loom_jobs WHERE id=1").Scan(&state, &attempts); err != nil {
			t.Fatal(err)
		}
		want := "pending"
		if i == 3 {
			want = "failed"
		}
		if state != want || attempts != i {
			t.Fatalf("%s %d", state, attempts)
		}
		if _, err = s.DB.Exec("UPDATE loom_jobs SET available=0 WHERE id=1"); err != nil {
			t.Fatal(err)
		}
	}
	if _, err = s.call(r, command{Op: "queue.dispatch", Kind: "recover", Owner: 1}); err != nil {
		t.Fatal(err)
	}
	if _, err = s.DB.Exec("UPDATE loom_jobs SET state='running',attempts=1,lease='dead',lease_until=0 WHERE id=2"); err != nil {
		t.Fatal(err)
	}
	var calls atomic.Int32
	success := func(context.Context, string) (string, error) { calls.Add(1); return `{"ok":true}`, nil }
	var wg sync.WaitGroup
	for i := 0; i < 4; i++ {
		wg.Add(1)
		go func() {
			defer wg.Done()
			if err := s.Tick(ctx, success); err != nil {
				t.Error(err)
			}
		}()
	}
	wg.Wait()
	if calls.Load() != 1 {
		t.Fatalf("multiple claim: %d", calls.Load())
	}
	var state string
	if err = s.DB.QueryRow("SELECT state FROM loom_jobs WHERE id=2").Scan(&state); err != nil || state != "completed" {
		t.Fatalf("recovery: %s %v", state, err)
	}
	if _, err = s.call(r, command{Op: "schedule.every", Name: "periodic", Kind: "digest", Owner: 1, Interval: 60}); err != nil {
		t.Fatal(err)
	}
	var before, after int64
	s.DB.QueryRow("SELECT next_run FROM loom_schedules").Scan(&before)
	if _, err = s.call(r, command{Op: "schedule.every", Name: "periodic", Kind: "digest", Owner: 1, Interval: 60}); err != nil {
		t.Fatal(err)
	}
	s.DB.QueryRow("SELECT next_run FROM loom_schedules").Scan(&after)
	if before != after {
		t.Fatal("registration resets schedule")
	}
	if _, err = s.DB.Exec("UPDATE loom_schedules SET next_run=0"); err != nil {
		t.Fatal(err)
	}
	if err = s.Tick(ctx, success); err != nil {
		t.Fatal(err)
	}
	if err = s.Tick(ctx, success); err != nil {
		t.Fatal(err)
	}
	if calls.Load() != 2 {
		t.Fatal("missed intervals should coalesce")
	}
}
