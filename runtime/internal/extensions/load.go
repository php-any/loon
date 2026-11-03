// extensions 包在标准库之后注册 Loom 的原生能力。
package extensions

import "github.com/php-any/origami/data"

// Load 是项目专用原生模块的统一注册入口。
func Load(vm data.VM) {
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
