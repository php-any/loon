package host

import (
	"bytes"
	"context"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

func invokeConsole(t *testing.T, entry, database string, args ...string) (int, string, string) {
	t.Helper()
	var out, errOut bytes.Buffer
	code := executeConsole(context.Background(), entry, database, args, &out, &errOut)
	return code, out.String(), errOut.String()
}

func TestConsoleDiscoveryDoesNotOpenDatabase(t *testing.T) {
	db := filepath.Join(t.TempDir(), "unused.sqlite")
	for _, args := range [][]string{{"list"}, {"help", "studio:tasks"}, {"inspire", "Loom"}} {
		code, out, errOut := invokeConsole(t, "bootstrap/console.php", db, args...)
		if code != 0 || out == "" || errOut != "" {
			t.Fatalf("%v: %d %s %s", args, code, out, errOut)
		}
	}
	if _, err := os.Stat(db); !os.IsNotExist(err) {
		t.Fatalf("help/list created database: %v", err)
	}
}

func TestConsoleORMAndQueue(t *testing.T) {
	db := filepath.Join(t.TempDir(), "studio.sqlite")
	s, err := openStudio(db)
	if err != nil {
		t.Fatal(err)
	}
	defer s.Close()
	for _, sql := range []string{
		`INSERT INTO loom_users(id,name,email,password) VALUES(1,'One','one@example.test','unused'),(2,'Two','two@example.test','unused')`,
		`INSERT INTO studio_tasks(user_id,title,completed) VALUES(1,'My task',1),(2,'Other task',1)`,
	} {
		if _, err = s.DB.Exec(sql); err != nil {
			t.Fatal(err)
		}
	}
	code, out, errOut := invokeConsole(t, "bootstrap/console.php", db, "studio:tasks", "1", "--completed", "--json")
	if code != 0 || !strings.Contains(out, "My task") || strings.Contains(out, "Other task") {
		t.Fatalf("%d %s %s", code, out, errOut)
	}
	code, out, errOut = invokeConsole(t, "bootstrap/console.php", db, "studio:digest", "1", "--dry-run")
	if code != 0 {
		t.Fatalf("%d %s %s", code, out, errOut)
	}
	var count int
	s.DB.QueryRow("SELECT count(*) FROM loom_jobs").Scan(&count)
	if count != 0 {
		t.Fatal("dry-run enqueued work")
	}
	code, out, errOut = invokeConsole(t, "bootstrap/console.php", db, "studio:digest", "1")
	if code != 0 {
		t.Fatalf("%d %s %s", code, out, errOut)
	}
	s.DB.QueryRow("SELECT count(*) FROM loom_jobs WHERE kind='studio.digest' AND owner=1").Scan(&count)
	if count != 1 {
		t.Fatalf("jobs=%d", count)
	}
	code, out, errOut = invokeConsole(t, "bootstrap/console.php", db, "studio:about", "--json")
	if code != 0 || !strings.Contains(out, "001_initial") {
		t.Fatalf("%d %s %s", code, out, errOut)
	}
}

func TestConsoleGenerateAndRun(t *testing.T) {
	dir := t.TempDir()
	for _, path := range []string{"app", "vendor"} {
		if err := os.CopyFS(filepath.Join(dir, path), os.DirFS(path)); err != nil {
			t.Fatal(err)
		}
	}
	for _, path := range []string{"bootstrap/console.php", "bootstrap/autoload.php", "routes/console.php"} {
		content, err := os.ReadFile(path)
		if err != nil {
			t.Fatal(err)
		}
		target := filepath.Join(dir, path)
		if err = os.MkdirAll(filepath.Dir(target), 0700); err != nil {
			t.Fatal(err)
		}
		if err = os.WriteFile(target, content, 0600); err != nil {
			t.Fatal(err)
		}
	}
	entry, db := filepath.Join(dir, "bootstrap/console.php"), filepath.Join(dir, "unused.sqlite")
	code, out, errOut := invokeConsole(t, entry, db, "make:command", "Greeting", "--command=app:greet {name=world}")
	if code != 0 {
		t.Fatalf("%d %s %s", code, out, errOut)
	}
	code, out, errOut = invokeConsole(t, entry, db, "app:greet", "Loom")
	if code != 0 || !strings.Contains(out, "命令已就绪，请在 handle 方法中实现业务逻辑。") {
		t.Fatalf("%d %s %s", code, out, errOut)
	}
	for _, args := range [][]string{{"make:command", "Greeting"}, {"make:command", "Class"}, {"make:command", "Duplicate", "--command=studio:about"}, {"make:command", "../Escape"}, {"studio:tasks"}} {
		code, _, errOut = invokeConsole(t, entry, db, args...)
		if code != 2 {
			t.Fatalf("%v: %d %s", args, code, errOut)
		}
	}
	if _, err := os.Stat(db); !os.IsNotExist(err) {
		t.Fatalf("scaffolding created database: %v", err)
	}
}
