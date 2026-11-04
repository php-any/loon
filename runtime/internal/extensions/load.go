// extensions 包在标准库之后注册 Loom 的原生能力。
package extensions

import (
	"database/sql"
	"sync"

	"github.com/php-any/origami/data"
)

// 进程级 *sql.DB 由定时器、命令和 HTTP 请求共同使用。请求虚拟机复制静态池时必须原样保留这一个池，不能按请求再打开一份。
var processPoolPolicy sync.Once

func allowProcessPoolAcrossRequests() {
	processPoolPolicy.Do(func() {
		data.RegisterNativeRequestPolicy[*sql.DB](data.NativeReadOnly, nil)
	})
}

// Load 是项目专用原生模块的统一注册入口。
func Load(vm data.VM) {
	allowProcessPoolAcrossRequests()
	vm.SetConstant("LOOM_RUNTIME", data.NewStringValue("loom"))
	vm.AddFunc(&protocolRequest{})
	vm.AddFunc(&sendMail{})
	vm.AddFunc(&webhook{})
	vm.AddFunc(&withSingletonLock{})
	vm.AddFunc(&modelDatabase{})
	vm.AddFunc(&withModelDatabase{})
	vm.AddFunc(&serveHTTP{})
	vm.AddFunc(&watchWebSocket{})
}
