package loom

import (
	"context"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
	"time"

	"github.com/gorilla/websocket"
)

const websocketFixture = `
$app = new \Loom\Application();
$app->get('/health', function ($request) { return new \Loom\Response('ok'); });
$app->websocket('/ws/{name}', function ($request, $connection) {
    $connection->writeText($request->parameter('name'));
    $connection->writeText($connection->readText());
});
return $app->handle(\Loom\Request::capture())->toArray();`

func dialWebSocket(t *testing.T, url string) *websocket.Conn {
	t.Helper()
	d := websocket.Dialer{HandshakeTimeout: 5 * time.Second}
	c, _, err := d.Dial("ws"+strings.TrimPrefix(url, "http"), nil)
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() { c.Close() })
	c.SetReadDeadline(time.Now().Add(5 * time.Second))
	return c
}

func TestWebSocketEchoAndHTTP(t *testing.T) {
	h := fixture(t, websocketFixture, nil)
	s := httptest.NewServer(h)
	defer s.Close()
	c := dialWebSocket(t, s.URL+"/ws/Ada")
	_, msg, err := c.ReadMessage()
	if err != nil || string(msg) != "Ada" {
		t.Fatalf("greeting=%q err=%v", msg, err)
	}
	if err := c.WriteMessage(websocket.TextMessage, []byte("你好 Loom")); err != nil {
		t.Fatal(err)
	}
	_, msg, err = c.ReadMessage()
	if err != nil || string(msg) != "你好 Loom" {
		t.Fatalf("echo=%q err=%v", msg, err)
	}
	for _, tc := range []struct {
		method, path string
		status       int
	}{
		{"GET", "/ws/Ada", 426}, {"HEAD", "/ws/Ada", 426}, {"POST", "/ws/Ada", 405}, {"GET", "/missing", 404}, {"GET", "/health", 200},
	} {
		if w := request(h, tc.method, tc.path, ""); w.Code != tc.status {
			t.Fatalf("%s %s: %d %s", tc.method, tc.path, w.Code, w.Body.String())
		}
	}
}

func TestWebSocketHandshakeRejections(t *testing.T) {
	h := fixture(t, websocketFixture, nil)
	s := httptest.NewServer(h)
	defer s.Close()
	for _, tc := range []struct {
		path, origin string
		status       int
	}{
		{"/ws/Ada", "https://evil.example", 403}, {"/missing", "", 404},
	} {
		headers := http.Header{}
		if tc.origin != "" {
			headers.Set("Origin", tc.origin)
		}
		d := websocket.Dialer{HandshakeTimeout: 5 * time.Second}
		c, r, err := d.Dial("ws"+strings.TrimPrefix(s.URL, "http")+tc.path, headers)
		if c != nil {
			c.Close()
		}
		if r != nil {
			defer r.Body.Close()
		}
		if err == nil || r == nil || r.StatusCode != tc.status {
			t.Fatalf("response=%v err=%v", r, err)
		}
	}
	r := httptest.NewRequest("GET", "/ws/Ada", nil)
	r.Header.Set("Connection", "Upgrade")
	r.Header.Set("Upgrade", "websocket")
	r.Header.Set("Sec-WebSocket-Version", "13")
	w := httptest.NewRecorder()
	h.ServeHTTP(w, r)
	if w.Code != 400 {
		t.Fatalf("invalid key: %d %s", w.Code, w.Body.String())
	}
}

func TestWebSocketLimitsAndCancellation(t *testing.T) {
	for _, mode := range []string{"size", "deadline", "cancel", "disconnect"} {
		t.Run(mode, func(t *testing.T) {
			h := fixture(t, websocketFixture, func(c *Config) {
				c.MaxWebSocketConnections = 1
				c.MaxWebSocketMessageBytes = 4
				c.WebSocketTimeout = 2 * time.Second
			})
			ctx, cancel := context.WithCancel(context.Background())
			defer cancel()
			s := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) { h.ServeHTTP(w, r.WithContext(ctx)) }))
			defer s.Close()
			c := dialWebSocket(t, s.URL+"/ws/Ada")
			if _, _, err := c.ReadMessage(); err != nil {
				t.Fatal(err)
			}
			// A waiting socket must not consume the ordinary HTTP request pool.
			if w := request(h, "GET", "/health", ""); w.Code != 200 {
				t.Fatalf("health: %d", w.Code)
			}
			r := httptest.NewRequest("GET", "/ws/Ada", nil)
			r.Header.Set("Connection", "Upgrade")
			r.Header.Set("Upgrade", "websocket")
			w := httptest.NewRecorder()
			h.ServeHTTP(w, r)
			if w.Code != 503 {
				t.Fatalf("capacity: %d", w.Code)
			}
			switch mode {
			case "size":
				c.WriteMessage(websocket.TextMessage, []byte("12345"))
			case "cancel":
				cancel()
			case "disconnect":
				c.Close()
			}
			if mode != "disconnect" {
				if _, _, err := c.ReadMessage(); err == nil {
					t.Fatal("expected connection close")
				}
			}
			until := time.Now().Add(3 * time.Second)
			for len(h.websocketSlots) != 0 && time.Now().Before(until) {
				time.Sleep(10 * time.Millisecond)
			}
			if len(h.websocketSlots) != 0 {
				t.Fatal("connection slot leaked")
			}
		})
	}
}

func TestWebSocketApplicationRoute(t *testing.T) {
	h, err := NewHandler(Config{Entry: "../../bootstrap/http.php"})
	if err != nil {
		t.Fatal(err)
	}
	s := httptest.NewServer(h)
	defer s.Close()
	c := dialWebSocket(t, s.URL+"/ws/echo")
	c.WriteMessage(websocket.TextMessage, []byte("app echo"))
	_, msg, err := c.ReadMessage()
	if err != nil || string(msg) != "app echo" {
		t.Fatalf("%q %v", msg, err)
	}
}

func TestWebSocketIsolationAndFailureCleanup(t *testing.T) {
	h := fixture(t, `
class Counter { public static int $value = 0; }
Counter::$value++;
$app = new \Loom\Application();
$app->websocket('/ws', function ($request, $connection) {
    $connection->writeText((string) Counter::$value);
    $connection->readText();
    throw new \RuntimeException('private failure');
});
return $app->handle(\Loom\Request::capture())->toArray();`, nil)
	s := httptest.NewServer(h)
	defer s.Close()
	first := dialWebSocket(t, s.URL+"/ws")
	second := dialWebSocket(t, s.URL+"/ws")
	for _, c := range []*websocket.Conn{first, second} {
		_, msg, err := c.ReadMessage()
		if err != nil || string(msg) != "1" {
			t.Fatalf("isolation: %q %v", msg, err)
		}
		if err := c.WriteMessage(websocket.TextMessage, []byte("fail")); err != nil {
			t.Fatal(err)
		}
		_, msg, err = c.ReadMessage()
		if err == nil || strings.Contains(string(msg), "private failure") {
			t.Fatalf("failure leaked: %q %v", msg, err)
		}
	}
	until := time.Now().Add(3 * time.Second)
	for len(h.websocketSlots) != 0 && time.Now().Before(until) {
		time.Sleep(10 * time.Millisecond)
	}
	if len(h.websocketSlots) != 0 {
		t.Fatal("exception leaked slots")
	}
}
