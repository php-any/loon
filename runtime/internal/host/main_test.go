package host

import (
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"net/http/httptest"
	"strings"
	"sync"
	"testing"
	"time"
)

func TestShowcasePagesAndAssets(t *testing.T) {
	h, err := newHandler("bootstrap/http.php")
	if err != nil {
		t.Fatal(err)
	}
	server := httptest.NewServer(h)
	defer server.Close()
	client := &http.Client{Timeout: 15 * time.Second}
	for _, tc := range []struct{ path, contains, contentType string }{
		{"/", "优雅成形", "text/html"},
		{"/playground", "contract-form", "text/html"},
		{"/guide", "View::render", "text/html"},
		{"/welcome?name=%3Cscript%3E", "&lt;script&gt;", "text/html"},
		{"/assets/app.css", "@media", "text/css"},
		{"/assets/app.js", "Promise.all", "javascript"},
		{"/assets/mark.svg", "<svg", "image/svg+xml"},
	} {
		t.Run(tc.path, func(t *testing.T) {
			r, err := client.Get(server.URL + tc.path)
			if err != nil {
				t.Fatal(err)
			}
			defer r.Body.Close()
			body, err := io.ReadAll(r.Body)
			if err != nil {
				t.Fatal(err)
			}
			if r.StatusCode != 200 || !strings.Contains(string(body), tc.contains) || !strings.Contains(r.Header.Get("Content-Type"), tc.contentType) {
				t.Fatalf("status=%d type=%s body=%.500s", r.StatusCode, r.Header.Get("Content-Type"), body)
			}
			if tc.contentType == "text/html" && !strings.Contains(r.Header.Get("Content-Security-Policy"), "script-src 'self'") {
				t.Fatal("missing page CSP")
			}
		})
	}
	var wg sync.WaitGroup
	for i := 0; i < 6; i++ {
		wg.Add(1)
		go func(i int) {
			defer wg.Done()
			token := fmt.Sprintf("showcase-%d", i)
			req, _ := http.NewRequest("GET", server.URL+"/api/isolation", nil)
			req.Header.Set("X-Loom-Probe", token)
			res, err := client.Do(req)
			if err != nil {
				t.Error(err)
				return
			}
			defer res.Body.Close()
			var payload struct {
				Token   string `json:"token"`
				Counter int    `json:"counter"`
			}
			if err := json.NewDecoder(res.Body).Decode(&payload); err != nil {
				t.Error(err)
				return
			}
			if res.StatusCode != 200 || payload.Token != token || payload.Counter != 1 {
				t.Errorf("status=%d payload=%+v", res.StatusCode, payload)
			}
		}(i)
	}
	wg.Wait()
}
