package host

import (
	"context"
	"encoding/json"
	"fmt"
	"net/http"
	"net/http/httptest"
	"path/filepath"
	"strings"
	"testing"

	"github.com/php-any/loom/framework"
)

func TestStudioBusinessFlow(t *testing.T) {
	path := filepath.Join(t.TempDir(), "studio.sqlite")
	services, err := openStudio(path)
	if err != nil {
		t.Fatal(err)
	}
	defer func() { services.Close() }()
	h, err := appHandler("bootstrap/http.php", services)
	if err != nil {
		t.Fatal(err)
	}
	type client struct {
		cookie *http.Cookie
		csrf   string
	}
	call := func(c *client, method, path, body string, want int) map[string]any {
		t.Helper()
		r := httptest.NewRequest(method, "http://studio.test"+path, strings.NewReader(body))
		r.Header.Set("Origin", "http://studio.test")
		r.Header.Set("Content-Type", "application/json")
		r.Header.Set("X-CSRF-Token", c.csrf)
		if c.cookie != nil {
			r.AddCookie(c.cookie)
		}
		w := httptest.NewRecorder()
		h.ServeHTTP(w, r)
		if w.Code != want {
			t.Fatalf("%s %s status %d want %d: %s", method, path, w.Code, want, w.Body.String())
		}
		for _, cookie := range w.Result().Cookies() {
			c.cookie = cookie
		}
		var out map[string]any
		if err := json.Unmarshal(w.Body.Bytes(), &out); err != nil {
			t.Fatal(err)
		}
		if token, ok := out["csrf"].(string); ok {
			c.csrf = token
		}
		return out
	}
	alice, bob := &client{}, &client{}
	call(alice, "GET", "/api/studio/state", "", 401)
	call(alice, "POST", "/api/studio/register", `{"name":"Alice","email":"alice@example.test","password":"correct horse battery"}`, 201)
	if alice.cookie == nil || !alice.cookie.HttpOnly || alice.cookie.SameSite != http.SameSiteLaxMode {
		t.Fatal("missing protected session cookie")
	}
	call(bob, "POST", "/api/studio/register", `{"name":"Bob","email":"bob@example.test","password":"another correct password"}`, 201)
	token := alice.csrf
	alice.csrf = "bad"
	call(alice, "POST", "/api/studio/tasks", `{"title":"blocked"}`, 403)
	alice.csrf = token
	task := call(alice, "POST", "/api/studio/tasks", `{"title":"Ship a real application <script>"}`, 201)
	id := fmt.Sprint(task["id"])
	call(bob, "PATCH", "/api/studio/tasks/"+id, `{"completed":true}`, 404)
	call(bob, "DELETE", "/api/studio/tasks/"+id, ``, 404)
	call(alice, "PATCH", "/api/studio/tasks/"+id, `{"completed":true}`, 200)
	if n := len(call(bob, "GET", "/api/studio/state", "", 200)["tasks"].([]any)); n != 0 {
		t.Fatalf("cross user leak: %d", n)
	}
	call(alice, "POST", "/api/studio/reports", `{"delay":0}`, 202)
	worker, err := loom.NewHandler(loom.Config{Entry: "bootstrap/jobs.php", Services: services})
	if err != nil {
		t.Fatal(err)
	}
	if err = services.Tick(context.Background(), worker.RunJob); err != nil {
		t.Fatal(err)
	}
	state := call(alice, "GET", "/api/studio/state", "", 200)
	if len(state["reports"].([]any)) != 1 {
		t.Fatalf("report missing: %v", state)
	}
	if state["jobs"].([]any)[0].(map[string]any)["state"] != "completed" {
		t.Fatalf("job failed: %v", state)
	}
	report := state["reports"].([]any)[0].(map[string]any)
	if report["total"] != float64(1) || report["completed"] != float64(1) {
		t.Fatalf("bad aggregate: %v", report)
	}
	// Duplicate delivery has no duplicate report side effect.
	if _, err = worker.RunJob(context.Background(), `{"id":1,"kind":"studio.digest","owner":1,"payload":[]}`); err != nil {
		t.Fatal(err)
	}
	call(alice, "POST", "/api/studio/reports", `{"delay":30}`, 202)
	if err = services.Tick(context.Background(), worker.RunJob); err != nil {
		t.Fatal(err)
	}
	state = call(alice, "GET", "/api/studio/state", "", 200)
	if len(state["reports"].([]any)) != 1 {
		t.Fatal("delayed job ran early or duplicate report")
	}
	call(alice, "POST", "/api/studio/schedule", `{"enabled":true}`, 200)
	if _, err = services.DB.Exec("UPDATE loom_schedules SET next_run=0"); err != nil {
		t.Fatal(err)
	}
	if err = services.Tick(context.Background(), worker.RunJob); err != nil {
		t.Fatal(err)
	}
	state = call(alice, "GET", "/api/studio/state", "", 200)
	if len(state["reports"].([]any)) != 2 {
		t.Fatal("scheduled report missing")
	}
	call(alice, "POST", "/api/studio/schedule", `{"enabled":false}`, 200)
	oldCookie := *alice.cookie
	call(alice, "POST", "/api/studio/logout", `{}`, 200)
	alice.cookie = &oldCookie
	call(alice, "GET", "/api/studio/state", "", 401)
	call(alice, "POST", "/api/studio/login", `{"email":"alice@example.test","password":"wrong wrong wrong"}`, 401)
	call(alice, "POST", "/api/studio/login", `{"email":"alice@example.test","password":"correct horse battery"}`, 200)
	services.Close()
	services, err = openStudio(path)
	if err != nil {
		t.Fatal(err)
	}
	h, err = appHandler("bootstrap/http.php", services)
	if err != nil {
		t.Fatal(err)
	}
	state = call(alice, "GET", "/api/studio/state", "", 200)
	if len(state["tasks"].([]any)) != 1 || len(state["reports"].([]any)) != 2 {
		t.Fatal("persistence failure")
	}
	r := httptest.NewRequest("POST", "http://studio.test/api/studio/login", strings.NewReader(`{"email":"alice@example.test","password":"correct horse battery"}`))
	r.Header.Set("Origin", "https://evil.test")
	r.Header.Set("Content-Type", "application/json")
	w := httptest.NewRecorder()
	h.ServeHTTP(w, r)
	if w.Code != 403 {
		t.Fatalf("cross origin auth: %d", w.Code)
	}
	project := call(alice, "POST", "/api/studio/projects", `{"name":"Release"}`, 201)
	projectID := fmt.Sprint(project["id"])
	call(bob, "POST", "/api/studio/tasks", `{"title":"Forbidden","project_id":`+projectID+`}`, 404)
	call(alice, "POST", "/api/studio/tasks", `{"title":"Project task","project_id":`+projectID+`}`, 201)
	filtered := call(alice, "GET", "/api/studio/state?project="+projectID, "", 200)
	if len(filtered["tasks"].([]any)) != 1 || len(filtered["migrations"].([]any)) != 2 {
		t.Fatal("project relationship or migration status")
	}
	call(bob, "GET", "/api/studio/state?project="+projectID, "", 404)
	call(alice, "POST", "/api/studio/cron/preview", `{"expression":"bad","timezone":"UTC"}`, 422)
	preview := call(alice, "POST", "/api/studio/cron/preview", `{"expression":"0 18 * * 1-5","timezone":"Asia/Shanghai"}`, 200)
	if len(preview["times"].([]any)) != 5 {
		t.Fatal("cron preview")
	}
	call(alice, "POST", "/api/studio/cron", `{"expression":"* * * * *","timezone":"Asia/Shanghai"}`, 200)
	var before, after int64
	services.DB.QueryRow("SELECT next_run FROM loom_schedules WHERE owner=1").Scan(&before)
	call(alice, "POST", "/api/studio/cron", `{"expression":"* * * * *","timezone":"Asia/Shanghai"}`, 200)
	services.DB.QueryRow("SELECT next_run FROM loom_schedules WHERE owner=1").Scan(&after)
	if before != after {
		t.Fatal("cron registration shifted schedule")
	}
	if _, err = services.DB.Exec("UPDATE loom_schedules SET next_run=0"); err != nil {
		t.Fatal(err)
	}
	worker, err = loom.NewHandler(loom.Config{Entry: "bootstrap/jobs.php", Services: services})
	if err != nil {
		t.Fatal(err)
	}
	if err = services.Tick(context.Background(), worker.RunJob); err != nil {
		t.Fatal(err)
	}
	state = call(alice, "GET", "/api/studio/state", "", 200)
	if len(state["reports"].([]any)) != 3 {
		t.Fatal("Cron failed to execute PHP report")
	}
	demo := call(alice, "POST", "/api/studio/migrations/demo", `{}`, 200)
	if demo["isolated"] != true || len(demo["events"].([]any)) != 5 {
		t.Fatal("migration demo")
	}
}
