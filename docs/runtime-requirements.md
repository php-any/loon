# Origami 运行时要求与验证

> 当前依赖为 Origami 正式版 `v1.0.10`，包含 `std/net/tcp`、`std/net/grpc`、`v1.0.7` 的 HTTP 并发修复、执行帧 Go context 与 `Database\bind`，以及编译期将未限定 `Name::class` 解析为 FQCN。`v1.0.7` 缺少 TCP 和 gRPC 包，不能用于构建当前宿主。

Loom 使用 `runtime/` 中的自定义 Go 宿主嵌入 Origami，通过 `./build/loom.exe doctor` 检查所需能力（需要配置可连接的 MySQL，检查会创建并删除一张唯一命名的普通表）。`runtime/go.mod` 固定模块标签 `v1.0.10`（提交 `ba8e74115e53a6a89694861dfce795fbf9c25cc7`）。此前版本曾固定提交 `ef4fe76d31954059dfa362168cce19fa7ae23679`（原生 Inject/Named）、`v1.0.8`（通用网络 API）与 `v1.0.9`（执行帧 context 与 `Database\bind`）。目录和构建方式见 [运行时目录说明](../runtime/README.md)。

```powershell
./scripts/build.ps1 -Test
./build/loom.exe doctor
./tests/run-all.ps1
```

`v1.0.9` 提供 `Context.SetGoContext()`、`Context.RawGoContext()`、子帧继承、include/require 只继承帧上已经保存的 context，以及帧回收清理。恢复绑定时写回 `RawGoContext()`；`GoContext()` 在未保存时会借用调用截止时间，把这个回退值写回帧会挡住之后的请求取消。`Database\bind` 让 `Database\DB` 除了 `*sql.DB` 也能在 `Database\Sql` 返回的 `*sql.Tx` 上执行。

指定 `-OrigamiSource` 或 `LOOM_ORIGAMI_SOURCE` 联调其他本地源码时，不自动修改提供的源码；调用方需确保它已包含上述上下文能力和查询器事务绑定。未具备上下文能力时，宿主测试或第一次绑定会明确报错。项目根目录可直接执行 `go -C runtime run ./cmd/loom serve`。

## 本轮发现的明确标准库错误

以下修复位于本机 Origami 仓库，未内嵌进 Loom，也不是 Loom 专用扩展。时区和 PDO 的独立脚本用例通过原生 PHP 与 Origami 对照；spawn 是 Origami 扩展，使用独立脚本回归验证。

1. `std/php/date_time_class.go`：`DateTimeImmutable::setTimezone()` 原先忽略时区并返回原对象。现在保留绝对时间、返回转换时区后的新对象；测试覆盖 UTC、Asia/Shanghai 及 America/New_York 的 DST 重复时刻。用例为 `tests/php/datetime_set_timezone_immutable_test.php`。

2. `std/spawn.go`：原先为异步闭包分配零长度执行帧，捕获变量或局部变量会导致索引越界崩溃。现在按闭包变量表创建执行帧；`tests/php/spawn_closure_frame_test.php` 覆盖静态方法中的多个捕获闭包。

3. `std/php/stream/stream_get_contents.go`：指定 length 时原先只读一次，子进程分批输出会被截断。现在持续读取到 EOF 或 length 上限；`tests/php/stream_get_contents_pipe_length_test.php` 在原生 PHP 与 Origami 对照验证，stream/proc 包回归通过。

这些上游修改已审核并推送到 Origami 远端仓库。部署到其他机器时，应携带基于上述修复构建的 Loom 宿主并运行 doctor；不要仅复制 Loom 后假设旧版运行时支持全部行为。`build/loom.exe` 是自定义宿主构建产物，不进入版本控制。

## 现有 std 能力的使用

- HTTP 请求隔离由标准库提供，脚本负责请求适配和应用装配。
- 数据库连接池和事务使用 `Database\Sql` 与 `Database\DB`。脚本负责 SQL、迁移编排和查询语义。`Database\bind` 让查询器跑在 `begin()` 返回的事务上。PDO 不是 Loom 的连接模型。
- 时区使用 DateTimeImmutable/DateTimeZone；认证使用 password_hash/password_verify、random_bytes、hash_equals。
- 子进程通过 proc_open 的参数数组和 stdout/stderr 管道启动，通过 spawn 与 Channel 并发读取两条输出管道，proc_get_status 与 proc_terminate 管理超时。Windows 管道 select 不保证读取不阻塞，因此监督循环与管道读取分开运行。仅使用已验证的接口，不依赖尚不支持的文件描述符或 stdin 管道。

## 新增通用网络能力

在确认现有 std 缺少原语后，经授权新增通用 API，未加入 Loom 专用接口：

- `std/net/tcp`：`Net\Tcp\Server`、`Conn`，通用字节流、超时、连接限额及生命周期，不认识 JSON 或业务 action。
- `std/net/grpc`：动态 `Schema`、unary `Server`、`Call`，Protobuf、HTTP/2、反射、gzip、消息/并发/连接限额。使用 `google.golang.org/grpc v1.76.0` 和 `github.com/bufbuild/protocompile v0.14.1`。服务路由、状态策略及 PHP 文件生成都在 Loom。
- `std/net/http`：可选 `Server::configure()`，对现有未配置服务器保留原行为。限制在 RequestVM 和 PHP 输入解析前生效。
- `std/net/websocket`：可选 `Conn::configure()`，消息大小、读写超时、上下文取消关闭。
- `runtime.RequestVM::BindContext()`：为非 HTTP 调用绑定通用取消上下文；TCP/gRPC 复用现有请求隔离机制。

独立回归位于上述包的 `*_test.go`、`std/net/internal/script/*_test.go` 和 `tests/php/net_api_test.php`。可在 Origami 仓库执行：

```powershell
go test -mod=readonly -timeout 30s ./std/net/tcp ./std/net/http ./std/net/websocket ./std/net/grpc ./std/net/internal/script
go run -mod=readonly ./zy.go tests/php/net_api_test.php
```

这些网络修改在 Origami `v1.0.8` 中发布；执行帧 context 与 `Database\bind` 在 `v1.0.9` 中发布；未限定 `Name::class` 的编译期 FQCN 解析在 `v1.0.10` 中发布。Loom 直接依赖 `v1.0.10` 模块标签，无需伪版本或 `replace`。Loom 的 `runtime/internal/host/modules.go` 显式注册所需模块，项目扩展通过 `runtime/internal/extensions/load.go` 注册。应用 streaming RPC 与 TLS 终止尚未提供。长驻命令通过脚本层 `StopSignal` 与 Origami `std/signal` 接收停止信号，可选执行 `onStop` 回调后直接退出，不内置排空；网络资源限制与协作取消的具体边界见 [协议说明](protocols.md) 与 [宿主说明](hosting.md)。

运行验证：`./build/loom.exe tests/run.php`；Windows 端到端验证：`./tests/e2e/http.ps1`。另有 `tests/e2e/protocols.py` 和 `tests/e2e/network_limits.py` 验证真实协议及慢客户端。测试使用独立数据库、临时输出、随机回环端口和独立作业进程，不使用业务数据库。

## 提交前审核与验证

2026-11-12 发布 Origami `v1.0.10`（`ba8e74115e53a6a89694861dfce795fbf9c25cc7`），在 `v1.0.9` 基础上合入 origami#23：编译期将未限定 `Name::class` 解析为 FQCN，行为与官方 PHP 一致。Loom `runtime/go.mod` 改为直接依赖该模块标签。

2026-11-12 发布 Origami `v1.0.9`（`232c9acd383fcd9652e6d3434dd5073e8914d836`），合并执行帧 Go context、`Database\bind` 与 `ReflectionAttribute` 的 `getArguments` / `newInstance`。Loom `runtime/go.mod` 改为直接依赖该模块标签，移除此前指向本机 `origami-frame-context` 的 `replace`。

2026-11-01 发布 Origami `v1.0.8`（`2ad471122dd5da424f9bcdc1a4f4923113539a36`），合并网络 API、原生 Inject/Named 与 `v1.0.7` 的 HTTP 并发修复。上游 runtime、TCP、gRPC、HTTP、WebSocket、网络脚本绑定的 Go 测试及 `net_api_test.php` 通过；Loom 从 Go 代理下载正式版后，宿主测试、构建、doctor、协议端到端和网络限制测试通过。GitHub Release 四个平台产物构建并发布成功。

2026-10-31 审核补充修复了 DateTimeImmutable 子类及克隆语义、网络绑定闭包和形参绑定、TCP 监听失败清理，以及取消后仍运行的 gRPC 回调限额。受影响 Go 包测试、`go vet -mod=readonly`、PHP 8.1.34 对照回归及 Loom 完整端到端测试通过。本轮未运行 race detector；TLS 与应用 streaming RPC 仍不在支持范围内。停止信号与用户 `onStop` 回调由脚本层提供，不含内置排空。

## 原生 Inject / Named 修复（已合入上游）

根因是 `annotationTargetParameter` 对参数目标始终返回空所属类名。解析参数时没有携带构造器声明类；提升参数分支也没有执行参数注解。补丁在构造器解析入口显式传递类名，在参数节点中保留，并在所有参数形式返回前处理注解。容器按参数的构造器声明类读取元数据，因此继承构造器仍使用父类的 Inject/Named。普通方法、全局函数和闭包没有构造器所有者，明确拒绝这些目标。

修复已提交并推送至 [Origami main](https://github.com/php-any/origami/commit/ef4fe76d31954059dfa362168cce19fa7ae23679)，并包含在正式版 `v1.0.8` 中。执行上下文和查询器事务绑定已合入正式版 `v1.0.9`（`232c9acd383fcd9652e6d3434dd5073e8914d836`）。

```powershell
./scripts/build.ps1 -Test
./build/loom.exe tests/integration/loom_native_inject_test.php
./build/loom.exe tests/run.php
```

回归覆盖原生容器的显式别名（与类型绑定使用不同对象）、无参数 Inject 的类型推断、提升参数、Named、继承构造器及三个非构造器错误目标。原始未修复宿主运行同一用例可复现“缺少所属类信息”。上游同时包含 `tests/php/container_constructor_annotations_test.php` 和 `std/container/constructor_annotations_test.go` 回归。
