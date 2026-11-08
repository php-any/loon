# 协议与网络限额

所有入口为 Origami 脚本。Loom 负责分帧、路由、业务异常映射、生成器和限额策略；Origami 提供可独立使用的通用网络 API，不包含 Loom 路由或业务操作。

## HTTP 与 WebSocket

`./build/loom.exe serve` 由 `HttpServer` 使用 `Net\Http\Server::configure()` 配置网络资源后监听，应用在此之前已由 `loom.php` 装好。默认最多 256 个已接收连接、128 个活动请求，请求体 1 MiB；Content-Length 超限直接返回 413，分块请求体也在脚本及超级全局变量解析前受限。请求槽满返回 503；连接槽满关闭新连接，连接计数包含空闲连接和升级后的 WebSocket。

读头超时 5 秒，读请求和写响应超时各 10 秒，keep-alive 空闲超时 30 秒，请求上下文上限 10 秒；脚本另有 10 秒协作执行限制。`maxHeaderBytes` 默认 16 KiB，底层 Go HTTP 解析器会保留自身的小量读取余量，不应把它理解为逐字节 socket 缓冲上限。响应正文上限固定 4 MiB，静态文件读取前检查大小。

`Loom\WebSocket::accept()` 校验升级头和同源 Origin，再调用 `Net\Websocket\upgrade()`。连接的 `configure()` 设置默认 1 MiB 消息上限、10 秒读写超时；请求取消会关闭连接。消息上限在完整消息分配前生效，写入也检查上限。业务处理结束后由脚本关闭连接。应用没有独立的回声或问候接口；WebSocket 传输与限额在 `tests/fixtures/network_limits.php` 和对应网络测试中验证。

## TCP

`./build/loom.exe tcp:serve` 使用 `Net\Tcp\Server`，默认地址 `127.0.0.1:18088`，可用 `LOOM_TCP_ADDRESS` 覆盖。`config/tcp.php` 的 `handler` 指定处理类，`TcpServer` 每条消息开一个作用域，从中解析该类并调用 `handle(array $message): array`；处理器位于 `app/Tcp/Handlers`，构造函数可注入服务。

每行一个 JSON 对象，LF 分隔并接受 CRLF。支持拆包和多帧粘包；每次按剩余容量读取，帧超过 1 MiB（不含 LF，包含可选 CR）或 EOF 前没有完整行时关闭连接。无效 JSON 返回 `invalid_message`，允许继续发送下一行。响应每行不超过 4 MiB，每连接最多处理 100 条消息。

默认连接上限 128，读写超时各 10 秒，连接总生命周期 60 秒。每个连接使用独立 RequestVM，顺序处理其中的消息；连接关闭释放配额。每条消息的作用域在回复前释放，脚本 `finally` 关闭连接。

```text
{"action":"ping"}
{"action":"release.snapshot","job":123,"token":"<短期任务凭证>"}
```

应用处理器为 `App\Tcp\Handlers\ReleaseSnapshotHandler`。`ping` 用于健康检查；`release.snapshot` 由发布摘要任务调用，校验任务凭证、租约状态和体验空间有效期后，通过任务模型读取验收统计。无效凭证返回 `invalid_or_expired_job_ticket`，未知动作返回 `unknown_action`。任务凭证由后台签发，不由页面手动配置。

## gRPC

`./build/loom.exe grpc:serve` 使用 `Net\Grpc\Schema` 和 `Net\Grpc\Server`，默认地址 `127.0.0.1:18089`，可用 `LOOM_GRPC_ADDRESS` 覆盖。真实 HTTP/2 与 Protobuf 传输支持 unary RPC、标准 proto imports、v1/v1alpha 反射、gzip、客户端取消和截止时间。

标准库编译配置和生成清单中的 proto，并把每次调用交给独立 RequestVM。`Loom\GrpcServer` 使用 `Call::method()`、`requestJson()`、`respondJson()`、`fail()`；ProtoJSON 在 std 与脚本之间传递，网络上仍是 Protobuf。普通消息用关联数组，wrapper 等类型遵循 ProtoJSON 标量表示。`Loom\Grpc\Dispatcher` 完成业务分派与状态映射：应用在 `loom.php` 里用 `$app->mapGrpc('/pkg.Service/Method', [类, 方法])` 登记方法，生成清单 `generated/grpc/routes.json` 补齐其余方法，显式映射优先于生成清单。不加载路由 PHP 文件；没有调用 `mapGrpc()` 的应用只能使用生成清单里的方法。gRPC 每次调用由 `GrpcServer` 开一个作用域，服务类从中解析。

默认最多 128 个连接、每连接 32 个并发流、进程级 128 个活动调用。准入在消息解码前执行，超额为 RESOURCE_EXHAUSTED。入站消息上限 1 MiB、出站 4 MiB、header list 16 KiB；gzip 解压后的消息仍受上限约束。请求最长 10 秒，握手最长 5 秒，连接空闲 30 秒，最大连接年龄 5 分钟并保留 10 秒结束窗口。反射流也受调用配额和截止时间约束。

`./build/loom.exe make:grpc proto/invoice.proto`（先按下方流程创建契约） 由脚本生成 `generated/grpc` 基类、首次创建业务类，并更新 `generated/grpc/routes.json`。重复生成保留业务实现；非生成基类、路径逃逸、符号冲突与不支持的 PHP 名称会被拒绝，写入前编译合并契约。

### 协议开发流程

```powershell
./build/loom.exe make:proto Invoice --package=app.billing
# 编辑 proto/invoice.proto，确定字段类型、编号及 RPC。
./build/loom.exe proto:check
./build/loom.exe make:grpc proto/invoice.proto --dry-run
./build/loom.exe make:grpc proto/invoice.proto
./build/loom.exe grpc:serve
```

`make:proto` 接受单个 PascalCase 名称，将文件名转换为 snake_case，默认包名为 `app`，拒绝路径、非法包名和覆盖已有文件。模板位于 `framework/resources/stubs/service.proto.stub`，创建 `<Name>Service`、`Get<Name>` RPC 与基础请求/响应消息。字段是起点，需要按业务定义，不会从模型或数据库自动推导协议。

`proto:check` 递归检查 `proto/`，并包含配置和生成清单中注册的协议；可传入单个路径，例如 `proto:check proto/invoice.proto`。检查包括语法、imports、跨文件编译、生成类/方法名称冲突和不支持的 streaming RPC，失败返回非零退出码。检查与预览不会创建生成目录或改写文件。它不执行实现代码，也不比较历史协议版本；字段编号复用、删除字段等兼容性检查属于后续能力。

`make:grpc --dry-run` 输出 CREATE、UPDATE、KEEP 文件清单。正式生成同样显示清单并保留已有业务文件；新 RPC 会更新生成基类，并提示需要在业务类中实现的方法。TODO 提示使用 `token_get_all` 静态读取业务类：未直接声明的方法、含 `unimplemented` 的占位方法会被提示，不执行 PHP 文件。继承或 trait 提供的实现需人工确认，提示不等于业务正确性验证，也不阻止生成。

上例生成 `generated/grpc/Billing/InvoiceServiceBase.php`、`app/Grpc/Services/Billing/InvoiceService.php` 并更新 `generated/grpc/routes.json`。实现业务方法后运行完整测试。新增 RPC 时重复执行检查、预览与生成，再补充业务实现；不要编辑生成基类。

当前应用服务只支持 unary RPC；含 streaming 方法的契约启动时明确报错。反射本身使用标准双向流。监听为明文回环地址，没有添加 TLS 终止或跨协议统一优雅停机编排；运行时截止时间是协作取消，不能强制终止任意忽略取消的第三方原生扩展。

## 配置与验证

`config/network.php` 返回的数组可添加 `http`、`websocket`、`tcp`、`grpc` 项覆盖默认值，完整字段见 `framework/src/Network/Network.php`。所有超时以毫秒计，所有大小以字节计，未知字段、非整数及非正值会被拒绝。例如：

```php
<?php
return [
    'tcp' => ['maxConnections' => 64, 'maxFrameBytes' => 65536],
    'grpc' => ['maxCalls' => 32, 'requestTimeoutMs' => 5000],
];
```

运行 `./build/loom.exe tests/run.php` 和 `tests/e2e/http.ps1`。真实协议测试：

```powershell
python -m venv .venv
.venv/Scripts/python -m pip install -r tests/e2e/requirements.txt
.venv/Scripts/python tests/e2e/protocols.py --origami build/loom.exe
python tests/e2e/network_limits.py --origami build/loom.exe
```

测试使用随机回环端口和临时文件，结束时只停止自己启动的进程。网络限额探针不加载 Loom 或业务数据库；标准库还保留独立 Go 与 Origami 脚本回归，见运行时要求。

生成基类使用 `Generated\Grpc` 命名空间，手写实现仍使用 `App\Grpc\Services`。当前 `grpc.package_prefix` 为 `app`，因此 proto 的 `app.users` 映射为代码中的 `Users`，RPC 路径不变。`generated/` 必须随部署交付并进入版本控制。
