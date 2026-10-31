package host

import (
	"context"
	"github.com/php-any/loom/framework"
	"path/filepath"
	"testing"
)

func TestORMAndLegacyMigration(t *testing.T) {
	path := filepath.Join(t.TempDir(), "old.sqlite")
	s, err := loom.OpenServices(path)
	if err != nil {
		t.Fatal(err)
	}
	plan, err := studioMigrations()
	if err != nil {
		t.Fatal(err)
	}
	// Previous Studio had these tables but no migration ledger.
	if _, err = s.DB.Exec(plan[0].Up + " INSERT INTO loom_users(id,name,email,password) VALUES(1,'A','a@test','test'); INSERT INTO studio_tasks(user_id,title) VALUES(1,'Old task');"); err != nil {
		t.Fatal(err)
	}
	s.Close()
	s, err = openStudio(path)
	if err != nil {
		t.Fatal(err)
	}
	defer s.Close()
	var title string
	var project any
	if err = s.DB.QueryRow("SELECT title,project_id FROM studio_tasks WHERE id=1").Scan(&title, &project); err != nil || title != "Old task" || project != nil {
		t.Fatalf("legacy data: %s %v %v", title, project, err)
	}
	if _, err = s.DB.Exec("DELETE FROM studio_tasks"); err != nil {
		t.Fatal(err)
	}
	h, err := loom.NewHandler(loom.Config{Entry: "tests/php/orm.php", Services: s})
	if err != nil {
		t.Fatal(err)
	}
	if _, err = h.RunJob(context.Background(), "{}"); err != nil {
		t.Fatal(err)
	}
}
