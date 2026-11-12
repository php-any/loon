# Go 运行时

项目自有 Go 源码、测试和模块文件统一放在 `runtime/`，编译产物放在根目录 `build/`。

```text
runtime/
  go.mod / go.sum            模块、依赖版本与校验
  cmd/loom/main.go           宿主入口
  internal/host/            脚本执行、项目定位、标准库装配与测试
  internal/extensions/      项目原生能力注册入口
```

## 构建与测试

需要 Go 1.27.1 或更新版本、PowerShell 7 和 Git。从项目根目录执行：

```powershell
./scripts/build.ps1 -Test
./build/loom.exe doctor
./build/loom.exe tests/run.php
./tests/run-all.ps1
```

默认依赖 Origami 正式版 `v1.0.9` 的模块版本，包含宿主所需的 `std/net/tcp`、`std/net/grpc`、原生 Inject/Named 修复、`v1.0.7` 的 HTTP 并发修复，以及执行帧 Go context 与让 `Database\DB` 在 `*sql.Tx` 上执行的 `Database\bind`。`v1.0.7` 缺少 TCP 和 gRPC 包，不能用于构建当前宿主。

因此在项目根目录可以直接执行 `go -C runtime run ./cmd/loom serve`。开发另一份已包含这两项能力的 Origami 源码时，用 `-OrigamiSource` 或 `LOOM_ORIGAMI_SOURCE` 覆盖；覆盖只写入被忽略的 `go.local.mod`，不修改提供的源码，也不把绝对路径写入提交的模块。构建以 readonly 模式运行，并关闭外部 Go workspace。`-Test` 先运行 Go 宿主测试；失败时不继续构建。

从 `runtime/` 单独运行测试：

```powershell
$env:GOWORK='off'
go test -mod=readonly ./...
go run ./cmd/loom serve
```

## 运行与部署

```powershell
./build/loom.exe serve
./build/loom.exe queue:work --once
./build/loom.exe gen-std
./scripts/dev-server.ps1
./scripts/dev-server.ps1 -Stop
```

Linux/macOS 的产物名称为 `build/loom`。不带参数或使用 `--help`、`-h` 时显示 Loom 命令列表。

`gen-std` 在进入脚本前执行。它按宿主实际装配的虚拟机，把 Origami 标准库、`Loom\Native` 函数，以及 Origami 的 Laravel 框架扩展写成 PHP 伪代码，默认放到项目根目录的 `.zy/std`。框架扩展只出现在伪代码里，脚本运行时不会注册。这些文件只供 IDE 索引。`-o` 可改输出目录；相对路径按当前工作目录解析。修改标准库装配或原生函数后，需要重新构建宿主再执行一次。

宿主支持两种入口：命令参数传给项目 `loom.php`；以 `.php` 或 `.zy` 结尾、包含路径分隔符或指向现有文件的首参数作为脚本路径直接执行（支持 tempnam 生成的无扩展名脚本）。脚本路径相对当前工作目录解析，后续参数原样传入。`PHP_BINARY` 指向当前宿主，任务与测试子进程因此使用同一套标准库和扩展。脚本退出码、异常及解析失败反映到进程退出码。

命令模式优先使用 `LOOM_ROOT` 指定的项目；未指定时从当前目录向上查找 `loom.php`，再从可执行文件目录向上查找。部署携带匹配的运行程序、脚本和资源，目标机器不需要 Go 或 Origami 源码。脚本未嵌入二进制。

## 扩展与边界

`internal/host/modules.go` 显式装配 std、PHP、HTTP、WebSocket、TCP、gRPC、网络注解、system，以及 MySQL 驱动。`internal/extensions.Load` 在标准库之后加载项目原生能力，注册 `LOOM_RUNTIME=loom` 常量，以及网页协议面板使用的 `Loom\Native\protocolRequest` 客户端函数。该函数通过真实 TCP 连接或 gRPC StringValue unary RPC 通信，具有 3 秒总超时和 64 KiB 响应限制；应用控制器固定目标配置与 RPC 方法。此客户端属于 Loom 扩展，不是 Origami v1.0.9 标准库 API。

依赖注入用到两个原生函数：`Loom\Native\withSingletonLock` 让同一个根 `ServiceProvider` 的单例并发解析只构造一次；`Loom\Native\withModelDatabase` 把作用域的 `Database` 挂在这次调用的执行帧上，`ServiceScope::run()` 用它让模型静态查询使用当前作用域，回调结束后恢复。作用域和容器本身是脚本，见 [依赖注入与作用域](../docs/core-services.md)。

业务和框架逻辑继续在 `app/`、`framework/` 中使用脚本编写。原生 Inject 修复已合入上游并固定依赖版本；显式本地源码覆盖仅用于开发，不参与默认构建。

### HTTP 与 WebSocket 生命周期

`Loom\Native\serveHttp` 复用 Origami 的 HTTP 路由与脚本处理器，在 Loom 宿主中管理 HTTP 监听与限额。普通请求有超时和请求并发限额；成功 Hijack 后取消 HTTP 专用计时，清除 HTTP socket 截止时间并释放请求名额，升级连接仍受总连接数限制。连接关闭会取消其脚本上下文并释放资源，停止服务器时也会关闭升级连接。

通知推送脚本通过 `Loom\Native\watchWebSocket` 启动单一原生读取循环处理关闭与 ping 控制帧，与脚本的单一写入循环配合。它仅用于推送连接，不可同时调用脚本 `readText()`，也不能重复启动读取循环。通知连接不再按 HTTP 超时周期主动重连。修改该能力后必须重新构建宿主并重启服务。
