package loom

import (
	"context"
	"encoding/json"
	"io"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strings"
	"sync"
	"testing"
	"time"
)

func fixture(t *testing.T, source string, configure func(*Config)) *Handler {
	t.Helper()
	entry := filepath.Join(t.TempDir(), "app.php")
	if err := os.WriteFile(entry, []byte("<?php\n"+source), 0600); err != nil {
		t.Fatal(err)
	}
	config := Config{Entry: entry, OnError: func(err error) { t.Log(err) }}
	if configure != nil {
		configure(&config)
	}
	h, err := NewHandler(config)
	if err != nil {
		t.Fatal(err)
	}
	return h
}

func request(h http.Handler, method, path, body string) *httptest.ResponseRecorder {
	r := httptest.NewRequest(method, path, strings.NewReader(body))
	if body != "" {
		r.Header.Set("Content-Type", "application/json")
	}
	w := httptest.NewRecorder()
	h.ServeHTTP(w, r)
	return w
}

func TestApplicationContract(t *testing.T) {
	h, err := NewHandler(Config{Entry: "../../bootstrap/http.php", OnError: func(err error) { t.Log(err) }})
	if err != nil {
		t.Fatal(err)
	}
	for _, tc := range []struct {
		method, path, body string
		status             int
		contains           string
	}{
		{"GET", "/", "", 200, "优雅成形"},
		{"GET", "/hello/Ada", "", 200, "Hello, Ada"},
		{"GET", "/welcome?name=%3Cscript%3E", "", 200, "&lt;script&gt;"},
		{"POST", "/greetings", `{"name":"Ada","times":2}`, 201, `"times":2`},
		{"POST", "/greetings", `{"name":"Ada","times":"2"}`, 422, `"reason":"type"`},
		{"POST", "/greetings", `{"name":"Ada"}`, 422, `"reason":"missing"`},
		{"POST", "/greetings", `{"name":null,"times":2}`, 422, `"reason":"null"`},
		{"POST", "/greetings", `{"name":"Ada","times":2,"admin":true}`, 422, `"reason":"unknown"`},
		{"POST", "/greetings", `{`, 400, "Malformed JSON"},
		{"POST", "/greetings", `[]`, 400, "Expected a JSON object"},
		{"POST", "/greetings", "", 415, "Expected application/json"},
		{"GET", "/missing", "", 404, "Not Found"},
		{"POST", "/hello/Ada", "", 405, "Method Not Allowed"},
		{"HEAD", "/hello/Ada", "", 200, ""},
	} {
		t.Run(tc.method+tc.path+tc.body, func(t *testing.T) {
			w := request(h, tc.method, tc.path, tc.body)
			if w.Code != tc.status || !strings.Contains(w.Body.String(), tc.contains) {
				t.Fatalf("status=%d body=%s", w.Code, w.Body.String())
			}
			if tc.method == "HEAD" && w.Body.Len() != 0 {
				t.Fatal("HEAD body")
			}
			if tc.status == 405 && w.Header().Get("Allow") != "GET, HEAD" {
				t.Fatalf("Allow=%s", w.Header().Get("Allow"))
			}
		})
	}
}

func TestHTTPConcurrentIsolation(t *testing.T) {
	h := fixture(t, `
class Counter { public static int $count = 0; }
Counter::$count++;
$GLOBALS['token'] = \Loom\Request::capture()->header('x-token');
register_shutdown_function(function () { Counter::$count = 99; $GLOBALS['token'] = 'shutdown'; });
return \Loom\Response::json(['count' => Counter::$count, 'token' => $GLOBALS['token']])->toArray();`, nil)
	server := httptest.NewServer(h)
	defer server.Close()
	client := &http.Client{Timeout: 10 * time.Second}
	var wg sync.WaitGroup
	for i := 0; i < 12; i++ {
		wg.Add(1)
		go func(i int) {
			defer wg.Done()
			token := strings.Repeat("x", i+1)
			r, _ := http.NewRequest("GET", server.URL, nil)
			r.Header.Set("X-Token", token)
			response, err := client.Do(r)
			if err != nil {
				t.Error(err)
				return
			}
			defer response.Body.Close()
			body, _ := io.ReadAll(response.Body)
			var result struct {
				Count int    `json:"count"`
				Token string `json:"token"`
			}
			if err := json.Unmarshal(body, &result); err != nil || response.StatusCode != 200 || result.Count != 1 || result.Token != token {
				t.Errorf("status=%d body=%s err=%v", response.StatusCode, body, err)
			}
		}(i)
	}
	wg.Wait()
}

func TestErrorsAndLimits(t *testing.T) {
	for _, source := range []string{
		`throw new \RuntimeException('secret');`,
		`echo 'secret'; return \Loom\Response::json(['ok' => true])->toArray();`,
		`register_shutdown_function(function () { echo 'secret'; }); return \Loom\Response::json([])->toArray();`,
		`register_shutdown_function(function () { throw new \RuntimeException('secret'); }); return \Loom\Response::json([])->toArray();`,
		`header('X-Test: bad'); return \Loom\Response::json([])->toArray();`,
		`ob_start(); echo 'secret'; return \Loom\Response::json([])->toArray();`,
		`return (new \Loom\Response('bad', 99))->toArray();`,
		`return (new \Loom\Response('bad', 204))->toArray();`,
		`return (new \Loom\Response('bad'))->withHeader('X-Test', "bad\r\nInjected: yes")->toArray();`,
		`return (new \Loom\Response('bad'))->withHeader('Content-Length', '0')->toArray();`,
		`return ['status' => 200, 'headers' => (object) [], 'body' => null];`,
		`$app = new \Loom\Application(); $app->route('GET', '/a/{id}', 'A'); $app->route('GET', '/a/fixed', 'B');`,
		`$c = new \Loom\Container(); $c->bind('a', function ($c) { return $c->get('a'); }); $c->get('a');`,
	} {
		h := fixture(t, source, nil)
		w := request(h, "GET", "/", "")
		if w.Code != 500 || strings.Contains(w.Body.String(), "secret") {
			t.Fatalf("status=%d body=%s", w.Code, w.Body.String())
		}
	}
	h := fixture(t, `return \Loom\Response::json(['ok' => true])->toArray();`, func(c *Config) { c.MaxBodyBytes = 4 })
	if w := request(h, "POST", "/", "12345"); w.Code != 413 {
		t.Fatalf("status=%d", w.Code)
	}
	h = fixture(t, `return \Loom\Response::html('too long')->toArray();`, func(c *Config) { c.MaxResponseBytes = 2 })
	if w := request(h, "GET", "/", ""); w.Code != 500 {
		t.Fatalf("status=%d", w.Code)
	}
	ctx, cancel := context.WithCancel(context.Background())
	cancel()
	w := httptest.NewRecorder()
	h.ServeHTTP(w, httptest.NewRequest("GET", "/", nil).WithContext(ctx))
	if w.Code != 504 {
		t.Fatalf("status=%d", w.Code)
	}
}

func TestApplicationHTTP(t *testing.T) {
	h, err := NewHandler(Config{Entry: "../../bootstrap/http.php", OnError: func(err error) { t.Log(err) }})
	if err != nil {
		t.Fatal(err)
	}
	server := httptest.NewServer(h)
	defer server.Close()
	client := &http.Client{Timeout: 10 * time.Second}
	response, err := client.Post(server.URL+"/greetings", "application/json", strings.NewReader(`{"name":"Ada","times":2}`))
	if err != nil {
		t.Fatal(err)
	}
	defer response.Body.Close()
	body, err := io.ReadAll(response.Body)
	if err != nil {
		t.Fatal(err)
	}
	if response.StatusCode != 201 || string(body) != `{"message":"Hello, Ada","times":2}` {
		t.Fatalf("status=%d body=%s", response.StatusCode, body)
	}
}

func TestOverloadAndRecovery(t *testing.T) {
	h := fixture(t, `return \Loom\Response::json(['ok' => true])->toArray();`, func(c *Config) { c.MaxConcurrentRequests = 1 })
	h.slots <- struct{}{}
	if w := request(h, "GET", "/", ""); w.Code != 503 {
		t.Fatalf("status=%d", w.Code)
	}
	<-h.slots
	if w := request(h, "GET", "/", ""); w.Code != 200 {
		t.Fatalf("status=%d body=%s", w.Code, w.Body.String())
	}
	if w := request(h, "POST", "/", string([]byte{0xff})); w.Code != 400 {
		t.Fatalf("status=%d", w.Code)
	}
}

func TestDeadlineAndRecovery(t *testing.T) {
	h := fixture(t, `
if (\Loom\Request::capture()->path() === '/loop') { while (true) {} }
return \Loom\Response::json(['ok' => true])->toArray();`, func(c *Config) { c.Timeout = 100 * time.Millisecond })
	start := time.Now()
	if w := request(h, "GET", "/loop", ""); w.Code != 504 {
		t.Fatalf("status=%d body=%s", w.Code, w.Body.String())
	}
	if time.Since(start) > 3*time.Second {
		t.Fatal("deadline not enforced")
	}
	if w := request(h, "GET", "/", ""); w.Code != 200 {
		t.Fatalf("recovery status=%d body=%s", w.Code, w.Body.String())
	}
}
