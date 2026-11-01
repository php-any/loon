package extensions

import (
	"bufio"
	"context"
	"fmt"
	"net"
	"strings"
	"testing"
	"time"
)

func testSMTP(t *testing.T) (string, <-chan string) {
	t.Helper()
	listener, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() { listener.Close() })
	result := make(chan string, 1)
	go func() {
		conn, err := listener.Accept()
		if err != nil {
			return
		}
		defer conn.Close()
		conn.SetDeadline(time.Now().Add(5 * time.Second))
		fmt.Fprint(conn, "220 localhost test SMTP\r\n")
		scanner := bufio.NewScanner(conn)
		var body strings.Builder
		inData := false
		for scanner.Scan() {
			line := scanner.Text()
			if inData {
				if line == "." {
					inData = false
					result <- body.String()
					fmt.Fprint(conn, "250 accepted\r\n")
				} else {
					body.WriteString(line + "\n")
				}
				continue
			}
			switch {
			case strings.HasPrefix(line, "EHLO"), strings.HasPrefix(line, "HELO"), strings.HasPrefix(line, "MAIL FROM"), strings.HasPrefix(line, "RCPT TO"):
				fmt.Fprint(conn, "250 OK\r\n")
			case line == "DATA":
				inData = true
				fmt.Fprint(conn, "354 continue\r\n")
			case line == "QUIT":
				fmt.Fprint(conn, "221 bye\r\n")
				return
			default:
				fmt.Fprint(conn, "500 unsupported\r\n")
			}
		}
	}()
	return listener.Addr().String(), result
}

func TestMailLocalDelivery(t *testing.T) {
	address, received := testSMTP(t)
	t.Setenv("LOOM_SMTP_ADDRESS", address)
	t.Setenv("LOOM_SMTP_FROM", "noreply@example.test")
	t.Setenv("LOOM_SMTP_TLS", "plain-local")
	t.Setenv("LOOM_SMTP_USER", "")
	if err := deliverMail(context.Background(), "test@example.test", "验证邮箱", "code: abc123"); err != nil {
		t.Fatal(err)
	}
	select {
	case body := <-received:
		if !strings.Contains(body, "To: <test@example.test>") || !strings.Contains(body, "code: abc123") || !strings.Contains(body, "Subject: =?utf-8?q?") {
			t.Fatalf("unexpected message %q", body)
		}
	case <-time.After(time.Second):
		t.Fatal("message missing")
	}
}

func TestMailRequiresTLS(t *testing.T) {
	address, _ := testSMTP(t)
	t.Setenv("LOOM_SMTP_ADDRESS", address)
	t.Setenv("LOOM_SMTP_FROM", "noreply@example.test")
	t.Setenv("LOOM_SMTP_TLS", "starttls")
	if err := deliverMail(context.Background(), "test@example.test", "subject", "body"); err == nil {
		t.Fatal("must reject SMTP without STARTTLS")
	}
}

func TestMailHeaderInjection(t *testing.T) {
	t.Setenv("LOOM_SMTP_ADDRESS", "127.0.0.1:1")
	t.Setenv("LOOM_SMTP_FROM", "noreply@example.test")
	if err := deliverMail(context.Background(), "test@example.test", "subject\r\nBcc: other@example.test", "body"); err == nil || !strings.Contains(err.Error(), "header") {
		t.Fatalf("header injection: %v", err)
	}
}
