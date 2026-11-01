package extensions

import (
	"bytes"
	"context"
	"crypto/hmac"
	"crypto/sha256"
	"crypto/tls"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"io"
	"net"
	"net/http"
	"net/netip"
	"net/url"
	"os"
	"regexp"
	"strconv"
	"time"

	"github.com/php-any/origami/data"
	"github.com/php-any/origami/node"
	"github.com/php-any/origami/utils"
)

var secretVariable = regexp.MustCompile(`^LOOM_WEBHOOK_SECRET_[A-Z0-9_]+$`)

func publicWebhookIP(ip net.IP) bool {
	addr, ok := netip.AddrFromSlice(ip)
	if !ok {
		return false
	}
	addr = addr.Unmap()
	if !addr.IsGlobalUnicast() || addr.IsPrivate() || addr.IsLoopback() || addr.IsLinkLocalUnicast() {
		return false
	}
	for _, block := range []string{"0.0.0.0/8", "100.64.0.0/10", "192.0.0.0/24", "192.0.2.0/24", "198.18.0.0/15", "198.51.100.0/24", "203.0.113.0/24", "240.0.0.0/4", "2001:db8::/32", "2001::/32", "2002::/16", "64:ff9b::/96"} {
		if netip.MustParsePrefix(block).Contains(addr) {
			return false
		}
	}
	return true
}

func webhookTarget(raw, secretEnv string) (*url.URL, error) {
	u, err := url.Parse(raw)
	if err != nil || u.Scheme != "https" || u.Hostname() == "" || u.User != nil || u.Fragment != "" || u.RawQuery != "" || (u.Port() != "" && u.Port() != "443") {
		return nil, fmt.Errorf("invalid webhook URL")
	}
	if !secretVariable.MatchString(secretEnv) || len(os.Getenv(secretEnv)) < 32 {
		return nil, fmt.Errorf("invalid webhook secret")
	}
	return u, nil
}

func webhookAddresses(ctx context.Context, host string) ([]net.IPAddr, error) {
	ips, err := net.DefaultResolver.LookupIPAddr(ctx, host)
	if err != nil || len(ips) == 0 {
		return nil, fmt.Errorf("webhook DNS unavailable")
	}
	for _, ip := range ips {
		if !publicWebhookIP(ip.IP) {
			return nil, fmt.Errorf("non-public webhook target")
		}
	}
	return ips, nil
}

func webhookSignature(secret, timestamp, body string) string {
	mac := hmac.New(sha256.New, []byte(secret))
	_, _ = mac.Write([]byte(timestamp + "." + body))
	return hex.EncodeToString(mac.Sum(nil))
}

func deliverWebhook(ctx context.Context, action, raw, secretEnv, body string) error {
	u, err := webhookTarget(raw, secretEnv)
	if err != nil {
		return err
	}
	ctx, cancel := context.WithTimeout(ctx, 10*time.Second)
	defer cancel()
	if _, err = webhookAddresses(ctx, u.Hostname()); err != nil {
		return err
	}
	if action == "validate" {
		return nil
	}
	if action != "send" || len(body) > 65536 {
		return fmt.Errorf("invalid webhook request")
	}
	transport := &http.Transport{Proxy: nil, TLSClientConfig: &tls.Config{MinVersion: tls.VersionTLS12}, DisableKeepAlives: true}
	defer transport.CloseIdleConnections()
	transport.DialContext = func(ctx context.Context, network, address string) (net.Conn, error) {
		host, port, err := net.SplitHostPort(address)
		if err != nil {
			return nil, err
		}
		ips, err := webhookAddresses(ctx, host)
		if err != nil {
			return nil, err
		}
		// 连接到已验证的地址，禁止在 Dial 中再次解析主机名。
		return (&net.Dialer{}).DialContext(ctx, network, net.JoinHostPort(ips[0].IP.String(), port))
	}
	client := &http.Client{Transport: transport, Timeout: 10 * time.Second, CheckRedirect: func(*http.Request, []*http.Request) error { return http.ErrUseLastResponse }}
	return postWebhook(ctx, client, u.String(), os.Getenv(secretEnv), body)
}

// 独立的 HTTP 边界允许测试本地传输，同时保留生产环境的地址校验。
func postWebhook(ctx context.Context, client *http.Client, target, secret, body string) error {
	request, err := http.NewRequestWithContext(ctx, http.MethodPost, target, bytes.NewBufferString(body))
	if err != nil {
		return err
	}
	timestamp := strconv.FormatInt(time.Now().Unix(), 10)
	request.Header.Set("Content-Type", "application/json")
	request.Header.Set("X-Loom-Timestamp", timestamp)
	request.Header.Set("X-Loom-Signature", "sha256="+webhookSignature(secret, timestamp, body))
	var event struct {
		ID string `json:"event_id"`
	}
	if json.Unmarshal([]byte(body), &event) != nil || event.ID == "" {
		return fmt.Errorf("missing event ID")
	}
	request.Header.Set("X-Loom-Event-ID", event.ID)
	response, err := client.Do(request)
	if err != nil {
		return err
	}
	defer response.Body.Close()
	_, _ = io.Copy(io.Discard, io.LimitReader(response.Body, 4096))
	if response.StatusCode < 200 || response.StatusCode >= 300 {
		return fmt.Errorf("webhook rejected")
	}
	return nil
}

type webhook struct{}

func (*webhook) GetName() string            { return "Loom\\Native\\webhook" }
func (*webhook) GetModifier() data.Modifier { return data.ModifierPublic }
func (*webhook) GetIsStatic() bool          { return true }
func (*webhook) GetReturnType() data.Types  { return data.NewBaseType("string") }
func (*webhook) GetParams() []data.GetValue {
	out := []data.GetValue{}
	for i, name := range []string{"action", "url", "secret_env", "body"} {
		out = append(out, node.NewParameter(nil, name, i, nil, data.NewBaseType("string")))
	}
	return out
}
func (*webhook) GetVariables() []data.Variable {
	out := []data.Variable{}
	for i, name := range []string{"action", "url", "secret_env", "body"} {
		out = append(out, node.NewVariable(nil, name, i, data.NewBaseType("string")))
	}
	return out
}
func (*webhook) Call(ctx data.Context) (data.GetValue, data.Control) {
	args := make([]string, 4)
	for i := range args {
		value, err := utils.ConvertFromIndex[string](ctx, i)
		if err != nil {
			return nil, utils.NewThrow(err)
		}
		args[i] = value
	}
	err := deliverWebhook(ctx.GoContext(), args[0], args[1], args[2], args[3])
	encoded, _ := json.Marshal(map[string]bool{"ok": err == nil})
	return data.NewStringValue(string(encoded)), nil
}
