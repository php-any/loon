package extensions

import (
	"context"
	"crypto/hmac"
	"crypto/sha256"
	"encoding/hex"
	"io"
	"net"
	"net/http"
	"net/http/httptest"
	"testing"
	"time"
)

func TestWebhookPublicAddresses(t *testing.T) {
	for _, raw := range []string{"127.0.0.1", "10.1.2.3", "172.16.0.1", "192.168.1.1", "169.254.169.254", "100.64.0.1", "0.0.0.0", "::1", "::ffff:127.0.0.1", "fc00::1", "fe80::1", "2002:7f00:1::", "64:ff9b::7f00:1", "192.0.2.1"} {
		if publicWebhookIP(net.ParseIP(raw)) {
			t.Errorf("accepted %s", raw)
		}
	}
	for _, raw := range []string{"8.8.8.8", "2606:4700:4700::1111"} {
		if !publicWebhookIP(net.ParseIP(raw)) {
			t.Errorf("rejected public %s", raw)
		}
	}
}

func TestWebhookLocalTransport(t *testing.T) {
	const secret = "a-long-secret-with-at-least-32-bytes"
	const body = `{"event_id":"notification-1","title":"hello"}`
	calls := 0
	server := httptest.NewTLSServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		calls++
		received, _ := io.ReadAll(r.Body)
		if string(received) != body || r.Header.Get("X-Loom-Event-ID") != "notification-1" || r.Header.Get("X-Loom-Signature") != "sha256="+webhookSignature(secret, r.Header.Get("X-Loom-Timestamp"), body) {
			t.Error("invalid signed request")
		}
		if r.URL.Path == "/redirect" {
			w.Header().Set("Location", "/target")
			w.WriteHeader(302)
			return
		}
		if r.URL.Path == "/failed" {
			w.WriteHeader(503)
			return
		}
		w.WriteHeader(204)
	}))
	defer server.Close()
	client := server.Client()
	client.Timeout = time.Second
	client.CheckRedirect = func(*http.Request, []*http.Request) error { return http.ErrUseLastResponse }
	if err := postWebhook(context.Background(), client, server.URL, secret, body); err != nil {
		t.Fatal(err)
	}
	if err := postWebhook(context.Background(), client, server.URL+"/failed", secret, body); err == nil {
		t.Fatal("accepted failure")
	}
	if err := postWebhook(context.Background(), client, server.URL+"/redirect", secret, body); err == nil {
		t.Fatal("accepted redirect")
	}
	if calls != 3 {
		t.Fatal("followed redirect")
	}
}
func TestWebhookValidationAndSignature(t *testing.T) {
	const secret = "a-long-secret-with-at-least-32-bytes"
	t.Setenv("LOOM_WEBHOOK_SECRET_TEST", secret)
	for _, raw := range []string{"http://example.com", "https://user:pass@example.com", "https://example.com:8443", "https://example.com/#fragment", "https://example.com/?token=secret"} {
		if _, err := webhookTarget(raw, "LOOM_WEBHOOK_SECRET_TEST"); err == nil {
			t.Errorf("accepted %s", raw)
		}
	}
	if _, err := webhookTarget("https://example.com/hook", "LOOM_SMTP_PASSWORD"); err == nil {
		t.Fatal("accepted unrelated secret")
	}
	if err := deliverWebhook(context.Background(), "send", "https://127.0.0.1/hook", "LOOM_WEBHOOK_SECRET_TEST", "{}"); err == nil {
		t.Fatal("connected to loopback")
	}
	mac := hmac.New(sha256.New, []byte(secret))
	mac.Write([]byte("123.{\"event_id\":\"test\"}"))
	if webhookSignature(secret, "123", "{\"event_id\":\"test\"}") != hex.EncodeToString(mac.Sum(nil)) {
		t.Fatal("invalid signature")
	}
}
