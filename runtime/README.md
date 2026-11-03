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

默认依赖 Origami 正式版 `v1.0.8`，包含宿主所需的 `std/net/tcp`、`std/net/grpc`、原生 Inject/Named 修复，以及 `v1.0.7` 的 HTTP 并发修复。`v1.0.7` 缺少这两个网络包，不能用于构建当前宿主。开发 Origami 时可用 `-OrigamiSource` 或 `LOOM_ORIGAMI_SOURCE` 覆盖依赖；源码必须包含所需标准库修复和 TCP/gRPC API，详见 [运行时要求](../docs/runtime-requirements.md)。

构建脚本将固定版本源码复制到被忽略的 `build/dependencies/`，按文件名顺序应用 `patches/` 中的全部补丁，再通过被忽略的 `go.local.mod` 和 `go.local.sum` 指向构建副本。执行上下文补丁为执行帧增加原生 Go context 传递，覆盖子调用、include/require 和帧池清理。include 与绑定恢复只复制帧上已保存的 context，不把 `GoContext()` 的截止时间回退值固化到帧上。查询器补丁让 `Database\DB` 可以在 `*sql.Tx` 上执行。构建不改写全局模块缓存。副本目录包含补丁摘要，补丁变化会生成新副本。显式 `-OrigamiSource` 不自动打补丁，该源码须已具备同等能力。构建以 readonly 模式运行，并关闭外部 Go workspace。提交的模块不包含机器路径。`-Test` 先运行 Go 宿主测试；失败时不继续构建。

完成上述配置后，也可从 `runtime/` 单独运行测试：

```powershell
$env:GOWORK='off'
go test -mod=readonly -modfile=go.local.mod ./...
go run -modfile=go.local.mod ./cmd/loom serve
```

## 运行与部署

```powershell
./build/loom.exe serve
./build/loom.exe queue:work --once
./scripts/dev-server.ps1
./scripts/dev-server.ps1 -Stop
```

Linux/macOS 的产物名称为 `build/loom`。不带参数或使用 `--help`、`-h` 时显示 Loom 命令列表。

宿主支持两种入口：命令参数传给项目 `loom.php`；以 `.php` 或 `.zy` 结尾、包含路径分隔符或指向现有文件的首参数作为脚本路径直接执行（支持 tempnam 生成的无扩展名脚本）。脚本路径相对当前工作目录解析，后续参数原样传入。`PHP_BINARY` 指向当前宿主，任务与测试子进程因此使用同一套标准库和扩展。脚本退出码、异常及解析失败反映到进程退出码。

命令模式优先使用 `LOOM_ROOT` 指定的项目；未指定时从当前目录向上查找 `loom.php`，再从可执行文件目录向上查找。部署携带匹配的运行程序、脚本和资源，目标机器不需要 Go 或 Origami 源码。脚本未嵌入二进制。

## 扩展与边界

`internal/host/modules.go` 显式装配 std、PHP、HTTP、WebSocket、TCP、gRPC、网络注解、system，以及 MySQL 驱动。`internal/extensions.Load` 在标准库之后加载项目原生能力，注册 `LOOM_RUNTIME=loom` 常量，以及网页协议面板使用的 `Loom\Native\protocolRequest` 客户端函数。该函数通过真实 TCP 连接或 gRPC StringValue unary RPC 通信，具有 3 秒总超时和 64 KiB 响应限制；应用控制器固定目标配置与 RPC 方法。此客户端属于 Loom 扩展，不是 Origami v1.0.8 标准库 API。

业务和框架逻辑继续在 `app/`、`framework/` 中使用脚本编写。原生 Inject 修复已合入上游并固定依赖版本；显式本地源码覆盖仅用于开发，不参与默认构建。

### HTTP 与 WebSocket 生命周期

`Loom\Native\serveHttp` 复用 Origami 的 HTTP 路由与脚本处理器，在 Loom 宿主中管理 HTTP 监听与限额。普通请求有超时和请求并发限额；成功 Hijack 后取消 HTTP 专用计时，清除 HTTP socket 截止时间并释放请求名额，升级连接仍受总连接数限制。连接关闭会取消其脚本上下文并释放资源，停止服务器时也会关闭升级连接。

通知推送脚本通过 `Loom\Native\watchWebSocket` 启动单一原生读取循环处理关闭与 ping 控制帧，与脚本的单一写入循环配合。它仅用于推送连接，不可同时调用脚本 `readText()`，也不能重复启动读取循环。通知连接不再按 HTTP 超时周期主动重连。修改该能力后必须重新构建宿主并重启服务。
