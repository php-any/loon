package loom

import (
	"context"
	"path/filepath"
	"sync"
	"testing"
)

func TestMigrationAtomicityDriftAndConcurrency(t *testing.T) {
	ctx := context.Background()
	path := filepath.Join(t.TempDir(), "migrations.sqlite")
	s, err := OpenServices(path)
	if err != nil {
		t.Fatal(err)
	}
	defer s.Close()
	plan := []Migration{{Version: "001", Up: "CREATE TABLE notes(id INTEGER PRIMARY KEY,title TEXT); INSERT INTO notes VALUES(1,'kept');", Down: "DROP TABLE notes;"}, {Version: "002", Up: "ALTER TABLE notes ADD COLUMN color TEXT DEFAULT 'sage';", Down: "ALTER TABLE notes DROP COLUMN color;"}}
	if err = s.Migrate(ctx, "app", plan[:1], false); err != nil {
		t.Fatal(err)
	}
	other, err := OpenServices(path)
	if err != nil {
		t.Fatal(err)
	}
	defer other.Close()
	var wg sync.WaitGroup
	for _, runner := range []*Services{s, other} {
		wg.Add(1)
		go func(runner *Services) {
			defer wg.Done()
			if err := runner.Migrate(ctx, "app", plan, false); err != nil {
				t.Error(err)
			}
		}(runner)
	}
	wg.Wait()
	status, err := s.MigrationStatus(ctx, "app", plan)
	if err != nil || len(status) != 2 || status[1].Batch != 2 {
		t.Fatalf("%v %v", status, err)
	}
	changed := append([]Migration{}, plan...)
	changed[0].Up += " SELECT 1;"
	if err = s.Migrate(ctx, "app", changed, false); err == nil {
		t.Fatal("checksum drift accepted")
	}
	if err = s.Migrate(ctx, "app", plan, true); err != nil {
		t.Fatal(err)
	}
	var title string
	if err = s.DB.QueryRow("SELECT title FROM notes WHERE id=1").Scan(&title); err != nil || title != "kept" {
		t.Fatal("rollback lost prior batch")
	}
	broken := []Migration{plan[0], {Version: "002", Up: "ALTER TABLE notes ADD COLUMN temporary TEXT; INSERT INTO absent VALUES(1);", Down: "SELECT 1;"}}
	if err = s.Migrate(ctx, "app", broken, false); err == nil {
		t.Fatal("expected failure")
	}
	var n int
	s.DB.QueryRow("SELECT COUNT(*) FROM pragma_table_info('notes') WHERE name='temporary'").Scan(&n)
	if n != 0 {
		t.Fatal("DDL did not rollback")
	}
	if _, err = migrationDemo(ctx); err != nil {
		t.Fatal(err)
	}
}
