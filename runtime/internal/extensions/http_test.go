package extensions

import (
	"context"
	"io"
	"net/http"
	"net/http/httptest"
	"strings"
	"sync"
	"testing"
	"time"

	"github.com/gorilla/websocket"
)

func testHTTPLimits() map[string]int {
	return map[string]int{"maxRequests": 1, "maxBodyBytes": 16, "requestTimeoutMs": 50}
}

func TestHTTPDeadlineRemains(t *testing.T) {
	completed := make(chan error, 1)
	server := httptest.NewServer(upgradeAwareHandler(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		<-r.Context().Done()
		completed <- r.Context().Err()
		w.WriteHeader(504)
	}), testHTTPLimits(), new(sync.Map)))
	defer server.Close()
	response, err := http.Get(server.URL)
	if err != nil {
		t.Fatal(err)
	}
	response.Body.Close()
	select {
	case err := <-completed:
		if err != context.Canceled {
			t.Fatal(err)
		}
	case <-time.After(time.Second):
		t.Fatal("HTTP timeout was disabled")
	}
}

func TestWebSocketOutlivesHTTPDeadlineAndReleasesRequestSlot(t *testing.T) {
	connections := new(sync.Map)
	ready := make(chan struct{})
	finished := make(chan error, 1)
	handler := http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path != "/socket" {
			io.WriteString(w, "ok")
			return
		}
		upgrader := websocket.Upgrader{}
		conn, err := upgrader.Upgrade(w, r, nil)
		if err != nil {
			finished <- err
			return
		}
		defer conn.Close()
		close(ready)
		time.Sleep(200 * time.Millisecond) // 普通 HTTP 截止时长的四倍。
		if err = r.Context().Err(); err != nil {
			finished <- err
			return
		}
		if err = conn.WriteMessage(websocket.TextMessage, []byte("still connected")); err != nil {
			finished <- err
			return
		}
		_, _, err = conn.ReadMessage()
		if websocket.IsCloseError(err, websocket.CloseNormalClosure) {
			err = nil
		}
		finished <- err
	})
	server := httptest.NewServer(upgradeAwareHandler(handler, testHTTPLimits(), connections))
	defer server.Close()
	conn, _, err := websocket.DefaultDialer.Dial("ws"+strings.TrimPrefix(server.URL, "http")+"/socket", nil)
	if err != nil {
		t.Fatal(err)
	}
	defer conn.Close()
	<-ready
	response, err := http.Get(server.URL + "/ordinary")
	if err != nil {
		t.Fatal(err)
	}
	response.Body.Close()
	if response.StatusCode != 200 {
		t.Fatalf("WebSocket occupied HTTP request slot: %d", response.StatusCode)
	}
	conn.SetReadDeadline(time.Now().Add(time.Second))
	_, message, err := conn.ReadMessage()
	if err != nil || string(message) != "still connected" {
		t.Fatalf("WebSocket expired: %q %v", message, err)
	}
	if err = conn.WriteControl(websocket.CloseMessage, websocket.FormatCloseMessage(websocket.CloseNormalClosure, ""), time.Now().Add(time.Second)); err != nil {
		t.Fatal(err)
	}
	select {
	case err = <-finished:
		if err != nil {
			t.Fatal(err)
		}
	case <-time.After(time.Second):
		t.Fatal("peer close did not end handler")
	}
}

func TestBodyLimitAndFailedUpgradeRetainHTTPProtection(t *testing.T) {
	handler := http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path == "/denied" {
			<-r.Context().Done()
			w.WriteHeader(403)
			return
		}
		w.WriteHeader(200)
	})
	server := httptest.NewServer(upgradeAwareHandler(handler, testHTTPLimits(), new(sync.Map)))
	defer server.Close()
	response, err := http.Post(server.URL, "text/plain", strings.NewReader(strings.Repeat("x", 17)))
	if err != nil {
		t.Fatal(err)
	}
	response.Body.Close()
	if response.StatusCode != 413 {
		t.Fatal(response.StatusCode)
	}
	request, _ := http.NewRequest("GET", server.URL+"/denied", nil)
	request.Header.Set("Connection", "Upgrade")
	request.Header.Set("Upgrade", "websocket")
	client := http.Client{Timeout: time.Second}
	response, err = client.Do(request)
	if err != nil {
		t.Fatal(err)
	}
	response.Body.Close()
	if response.StatusCode != 403 {
		t.Fatal(response.StatusCode)
	}
}
