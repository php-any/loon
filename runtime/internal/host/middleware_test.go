package host

import (
	"net/http/httptest"
	"path/filepath"
	"strings"
	"testing"
)

func TestApplicationMiddleware(t *testing.T) {
	services, err := openStudio(filepath.Join(t.TempDir(), "middleware.sqlite"))
	if err != nil {
		t.Fatal(err)
	}
	defer services.Close()
	h, err := appHandler("bootstrap/http.php", services)
	if err != nil {
		t.Fatal(err)
	}
	// Every protected route must reject unauthenticated input before parsing JSON or executing business code.
	for _, route := range []struct{ method, path string }{
		{"GET", "/state"}, {"POST", "/logout"}, {"POST", "/tasks"},
		{"PATCH", "/tasks/1"}, {"DELETE", "/tasks/1"}, {"POST", "/reports"},
		{"POST", "/projects"}, {"POST", "/cron/preview"}, {"POST", "/cron"},
		{"POST", "/migrations/demo"}, {"POST", "/schedule"}, {"POST", "/jobs/1/retry"},
	} {
		r := httptest.NewRequest(route.method, "http://studio.test/api/studio"+route.path, strings.NewReader("invalid"))
		w := httptest.NewRecorder()
		h.ServeHTTP(w, r)
		if w.Code != 401 {
			t.Fatalf("%s %s: %d %s", route.method, route.path, w.Code, w.Body.String())
		}
	}
	for _, path := range []string{"/greetings", "/api/studio/login", "/api/studio/register"} {
		for _, input := range []struct {
			contentType, body string
			status            int
		}{
			{"text/plain", "{}", 415},
			{"application/json", "{", 400},
			{"application/json", "[]", 400},
			{"application/json", "{}", 422},
		} {
			r := httptest.NewRequest("POST", "http://studio.test"+path, strings.NewReader(input.body))
			r.Header.Set("Content-Type", input.contentType)
			w := httptest.NewRecorder()
			h.ServeHTTP(w, r)
			if w.Code != input.status {
				t.Fatalf("%s %s: %d want %d: %s", path, input.body, w.Code, input.status, w.Body.String())
			}
		}
	}
}
