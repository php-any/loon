package extensions

import (
	"context"
	"crypto/tls"
	"encoding/json"
	"fmt"
	"io"
	"mime"
	"net"
	"net/mail"
	"net/smtp"
	"os"
	"strings"
	"time"

	"github.com/php-any/origami/data"
	"github.com/php-any/origami/node"
	"github.com/php-any/origami/utils"
)

// 传输配置由服务端管理，绝不由 HTTP 请求提供。
// 仅对显式配置的回环测试服务器允许明文传输。
func deliverMail(ctx context.Context, recipient, subject, body string) error {
	address, from := os.Getenv("LOOM_SMTP_ADDRESS"), os.Getenv("LOOM_SMTP_FROM")
	if address == "" || from == "" {
		return fmt.Errorf("SMTP is not configured")
	}
	for _, value := range []string{recipient, subject, from} {
		if strings.ContainsAny(value, "\r\n") {
			return fmt.Errorf("invalid mail header")
		}
	}
	if len(body) > 65536 || len(subject) > 200 {
		return fmt.Errorf("mail too large")
	}
	toAddress, err := mail.ParseAddress(recipient)
	if err != nil {
		return err
	}
	fromAddress, err := mail.ParseAddress(from)
	if err != nil {
		return err
	}
	host, _, err := net.SplitHostPort(address)
	if err != nil {
		return err
	}
	ctx, cancel := context.WithTimeout(ctx, 15*time.Second)
	defer cancel()
	conn, err := (&net.Dialer{}).DialContext(ctx, "tcp", address)
	if err != nil {
		return err
	}
	defer conn.Close()
	stop := context.AfterFunc(ctx, func() { conn.Close() })
	defer stop()
	deadline, _ := ctx.Deadline()
	if err = conn.SetDeadline(deadline); err != nil {
		return err
	}
	tlsConfig := &tls.Config{ServerName: host, MinVersion: tls.VersionTLS12}
	mode := os.Getenv("LOOM_SMTP_TLS")
	if mode == "implicit" {
		secure := tls.Client(conn, tlsConfig)
		if err = secure.HandshakeContext(ctx); err != nil {
			return err
		}
		conn = secure
	}
	client, err := smtp.NewClient(conn, host)
	if err != nil {
		return err
	}
	defer client.Close()
	if mode == "plain-local" {
		peer, ok := conn.RemoteAddr().(*net.TCPAddr)
		if !ok || !peer.IP.IsLoopback() {
			return fmt.Errorf("plaintext SMTP requires loopback")
		}
	} else if mode != "implicit" {
		if mode != "" && mode != "starttls" {
			return fmt.Errorf("invalid SMTP TLS mode")
		}
		if err = client.StartTLS(tlsConfig); err != nil {
			return err
		}
	}
	if username := os.Getenv("LOOM_SMTP_USER"); username != "" {
		if err = client.Auth(smtp.PlainAuth("", username, os.Getenv("LOOM_SMTP_PASSWORD"), host)); err != nil {
			return err
		}
	}
	if err = client.Mail(fromAddress.Address); err != nil {
		return err
	}
	if err = client.Rcpt(toAddress.Address); err != nil {
		return err
	}
	writer, err := client.Data()
	if err != nil {
		return err
	}
	message := "From: " + fromAddress.String() + "\r\nTo: " + toAddress.String() + "\r\nSubject: " + mime.QEncoding.Encode("utf-8", subject) + "\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=utf-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n" + strings.ReplaceAll(strings.ReplaceAll(body, "\r\n", "\n"), "\n", "\r\n") + "\r\n"
	if _, err = io.WriteString(writer, message); err != nil {
		return err
	}
	if err = writer.Close(); err != nil {
		return err
	}
	// DATA 被接受即视为成功，即使之后 QUIT 时连接断开。
	_ = client.Quit()
	return nil
}

type sendMail struct{}

func (*sendMail) GetName() string            { return "Loom\\Native\\sendMail" }
func (*sendMail) GetModifier() data.Modifier { return data.ModifierPublic }
func (*sendMail) GetIsStatic() bool          { return true }
func (*sendMail) GetReturnType() data.Types  { return data.NewBaseType("string") }
func (*sendMail) GetParams() []data.GetValue {
	out := []data.GetValue{}
	for i, name := range []string{"recipient", "subject", "body"} {
		out = append(out, node.NewParameter(nil, name, i, nil, data.NewBaseType("string")))
	}
	return out
}
func (*sendMail) GetVariables() []data.Variable {
	out := []data.Variable{}
	for i, name := range []string{"recipient", "subject", "body"} {
		out = append(out, node.NewVariable(nil, name, i, data.NewBaseType("string")))
	}
	return out
}
func (*sendMail) Call(ctx data.Context) (data.GetValue, data.Control) {
	args := make([]string, 3)
	for i := range args {
		value, err := utils.ConvertFromIndex[string](ctx, i)
		if err != nil {
			return nil, utils.NewThrow(err)
		}
		args[i] = value
	}
	err := deliverMail(ctx.GoContext(), args[0], args[1], args[2])
	// 避免泄露传输凭据、消息内容或服务提供商的诊断信息。
	encoded, _ := json.Marshal(map[string]bool{"ok": err == nil})
	return data.NewStringValue(string(encoded)), nil
}
