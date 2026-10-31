package loom

import (
	"context"
	_ "embed"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"sync"
	"time"

	"github.com/php-any/origami/data"
	"github.com/php-any/origami/runtime"
)

//go:embed php/console.php
var consoleSource string

type consoleKey struct{}
type consoleState struct {
	args        []string
	out, errOut io.Writer
	err         error
	remaining   int
	mu          sync.Mutex
	cancel      context.CancelFunc
}

func (s *consoleState) write(text string, stderr bool) error {
	s.mu.Lock()
	defer s.mu.Unlock()
	if s.err != nil {
		return s.err
	}
	if len(text) > s.remaining {
		s.err = fmt.Errorf("命令输出超过大小限制")
		s.cancel()
		return s.err
	}
	s.remaining -= len(text)
	w := s.out
	if stderr {
		w = s.errOut
	}
	n, err := io.WriteString(w, text)
	if err == nil && n != len(text) {
		err = io.ErrShortWrite
	}
	if err != nil {
		s.err = err
		s.cancel()
	}
	return err
}

type consoleFunction struct{}

func (*consoleFunction) GetName() string { return "Loom\\console_io" }
func (*consoleFunction) GetParams() []data.GetValue {
	return []data.GetValue{data.NewParameter("command", 0)}
}
func (*consoleFunction) GetVariables() []data.Variable {
	return []data.Variable{data.NewVariable("command", 0, data.NewBaseType("string"))}
}
func (*consoleFunction) Call(ctx data.Context) (data.GetValue, data.Control) {
	vm, ok := ctx.GetVM().(*runtime.RequestVM)
	if !ok || vm.HTTPRequest() == nil {
		return nil, data.NewErrorThrow(nil, fmt.Errorf("终端输入输出必须在命令作用域内使用"))
	}
	state, ok := vm.HTTPRequest().Context().Value(consoleKey{}).(*consoleState)
	if !ok {
		return nil, data.NewErrorThrow(nil, fmt.Errorf("HTTP 请求和后台任务不能使用终端输入输出"))
	}
	v, _ := ctx.GetIndexValue(0)
	var command struct {
		Op     string `json:"op"`
		Text   string `json:"text"`
		Stderr bool   `json:"stderr"`
	}
	if v == nil {
		return nil, data.NewErrorThrow(nil, fmt.Errorf("缺少终端操作"))
	}
	if err := json.Unmarshal([]byte(v.AsString()), &command); err != nil {
		return nil, data.NewErrorThrow(nil, err)
	}
	switch command.Op {
	case "argv":
		raw, _ := json.Marshal(state.args)
		return data.NewStringValue(string(raw)), nil
	case "write":
		if err := state.write(command.Text, command.Stderr); err != nil {
			return nil, data.NewErrorThrow(nil, err)
		}
		return data.NewStringValue("null"), nil
	}
	return nil, data.NewErrorThrow(nil, fmt.Errorf("未知终端操作"))
}

// ConsoleRunner 执行 PHP 命令入口，不启动 HTTP 服务或队列工作进程。
// 内部请求仅为现有原生服务传递执行上下文，不暴露为 HTTP 路由。
// PHP 对象归属于本次执行隔离的 RequestVM。
type ConsoleRunner struct{ host *Handler }

func NewConsole(config Config) (*ConsoleRunner, error) {
	if config.Timeout == 0 {
		config.Timeout = 60 * time.Second
	}
	h, err := NewHandler(config)
	if err != nil {
		return nil, err
	}
	return &ConsoleRunner{host: h}, nil
}
func (c *ConsoleRunner) Run(ctx context.Context, args []string, stdout, stderr io.Writer) (code int, err error) {
	h := c.host
	if stdout == nil {
		stdout = io.Discard
	}
	if stderr == nil {
		stderr = io.Discard
	}
	ctx, cancel := context.WithTimeout(ctx, h.config.Timeout)
	defer cancel()
	copied := append([]string{}, args...)
	state := &consoleState{args: copied, out: stdout, errOut: stderr, remaining: h.config.MaxResponseBytes, cancel: cancel}
	vm := runtime.NewRequestVM(h.base).(*runtime.RequestVM)
	restore := vm.Activate()
	defer restore()
	deadline := runtime.BeginRequestDeadline(ctx)
	defer deadline()
	r, _ := http.NewRequestWithContext(context.WithValue(ctx, consoleKey{}, state), "CONSOLE", "http://loom-console/", nil)
	discard := &captureWriter{header: make(http.Header)}
	vm.BindHTTP(r, discard)
	vm.SetOutputWriter(func(text string) { _ = state.write(text, false) })
	defer func() {
		if failure := recover(); failure != nil {
			err = fmt.Errorf("命令执行失败：%v", failure)
		}
		if state.err != nil {
			err = state.err
		} else if ctx.Err() != nil {
			err = ctx.Err()
		}
		if err != nil {
			code = 1
		}
	}()
	defer func() {
		vm.RunShutdownCallbacks()
		if thrown := vm.TakeThrow(); thrown != nil {
			err = fmt.Errorf("命令结束回调失败：%s", thrown.AsString())
		}
		if vm.OutputBufferLevel() != 0 {
			err = fmt.Errorf("命令入口未关闭输出缓冲区")
		}
		if len(discard.header) != 0 {
			err = fmt.Errorf("命令入口不能发送 HTTP 响应头")
		}
	}()
	p := vm.PrepareParse(h.parser)
	for _, source := range []struct{ name, code string }{{"loom", frameworkSource}, {"services", servicesSource}, {"orm", ormSource}, {"console", consoleSource}} {
		program, control := p.ParseString(source.code, "loom://framework/"+source.name+".php")
		if control != nil {
			return 1, fmt.Errorf("命令框架初始化失败：%s", control.AsString())
		}
		if _, control = program.GetValue(vm.CreateContext(p.GetVariables())); control != nil {
			return 1, fmt.Errorf("命令框架初始化失败：%s", control.AsString())
		}
	}
	result, control := vm.LoadAndRun(h.config.Entry)
	if control != nil {
		return 1, fmt.Errorf("命令应用执行失败：%s", control.AsString())
	}
	value, ok := result.(*data.IntValue)
	if !ok {
		return 1, fmt.Errorf("命令入口必须返回整数退出码")
	}
	code, _ = value.AsInt()
	if code < 0 || code > 255 {
		return 1, fmt.Errorf("退出码必须在 0–255 范围内")
	}
	return code, nil
}
