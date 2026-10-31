# 多协议应用与完整示例

## 职责和目录

`app/Http/Controllers` 处理 HTTP，`app/WebSocket/Handlers` 处理 WebSocket，`app/Tcp/Handlers` 处理 TCP 消息，`app/Grpc/Services` 实现 RPC 应用行为。它们调用 `app/Services` 中与协议无关的业务逻辑。`app/Grpc/Services/GreeterService` 是 RPC 入口，`app/Services/GreetingService` 是共享业务服务。

监听器与服务器归 `runtime/internal/host` 装配，通用 PHP 消息执行及 TCP 分帧归 `runtime/framework`。PHP 类不启动端口监听。`bootstrap/*.php` 加载应用并装配入口，`routes/websocket.php` 单独注册 WebSocket 路由。

每个 HTTP 请求、TCP 消息或 gRPC 调用使用独立的 RequestVM；同一 TCP 连接上的相邻消息也不会保留 PHP 全局状态。WebSocket 仍按连接分配作用域。业务持久状态应放数据库等显式存储。

## 启动与配置

先按 README 配置 Origami 路径并执行 `composer dump-autoload`，然后：

```powershell
composer start
```

默认 HTTP/WebSocket 监听 `127.0.0.1:18087`，TCP 监听 `127.0.0.1:18088`，gRPC 监听 `127.0.0.1:18089`。`config/loom.json` 中增加的配置为：

```json
{
  "tcp": {"address": "127.0.0.1:18088", "entry": "bootstrap/tcp.php"},
  "grpc": {
    "address": "127.0.0.1:18089", "entry": "bootstrap/grpc.php",
    "import_paths": ["proto"], "proto_files": ["greeter.proto"], "reflection": true
  }
}
```

这是配置片段，应保留原有 HTTP、Console、Jobs 和数据库字段。删除某一协议字段或设为 `null` 即可禁用该服务；提供对象时必须同时包含 `address` 和 `entry`，gRPC 还必须提供 `import_paths` 和 `proto_files`。Console 和迁移命令不会启动这些监听器。路径相对于应用根目录；`proto_files` 中的文件名相对于 import 目录。

按 Ctrl+C 停止宿主时 TCP 连接关闭，gRPC 等待正在处理的请求结束，最多等待 15 秒后停止。示例使用本地明文连接，没有配置 TLS 或身份验证。

## HTTP：带验证和依赖注入的业务调用

```powershell
Invoke-RestMethod -Method Post -Uri http://127.0.0.1:18087/greetings -ContentType application/json -Body '{"name":"Ada","times":2}'
```

返回 HTTP 201，JSON 为 `{"message":"Hello, Ada","times":2}`。`times` 是示例结果中的参数，不代表重复发送响应。`CreateGreetingController` 使用现有 HTTP 输入验证，`bootstrap/http.php` 通过容器注入共享 `GreetingService`。原有页面、Studio API、登录、ORM、队列和计划任务示例继续保留。

## WebSocket：Echo 和业务消息

```powershell
go -C runtime run ./cmd/protocol-client --protocol=websocket --name=Ada
go -C runtime run ./cmd/protocol-client --protocol=websocket --addr=ws://127.0.0.1:18087/ws/echo --name=hello
```

第一条连接 `/ws/greet`，发送名字文本，接收 `{"message":"Hello, Ada","times":1}`；第二条连接 `/ws/echo`，接收原样的 `hello`。两个示例均处理一条消息后结束连接。名字为空或超过 100 字节时，GreetingHandler 返回 `{"error":"invalid_name"}`。连接限制和超时沿用 [WebSocket 文档](framework.md#websocket)。

## TCP：长连接上的 JSON 行协议

```powershell
go -C runtime run ./cmd/protocol-client --protocol=tcp --name=Ada
```

客户端在同一个连接中依次发送三个以换行符结束的 JSON 对象：

```json
{"action":"ping"}
{"action":"greet","name":"Ada"}
{"action":"unknown"}
```

依次收到：

```json
{"ok":true,"result":{"pong":true}}
{"ok":true,"result":{"message":"Hello, Ada","times":1}}
{"ok":false,"error":"unknown_action"}
```

JSON 字段顺序不作保证。`greet` 的名字必须是非空字符串，去除两端空白前最多 100 字节；否则返回 `invalid_name`。无效 JSON、数组、null 等非对象输入返回 `invalid_message`，随后仍可继续发送下一条消息。框架执行异常返回 `internal_error`，详细信息写入宿主日志。

宿主按换行分帧，处理 TCP 粘包和拆包；每帧正文最多 1 MiB，超过扫描上限会关闭连接。默认最多 64 个连接，超额连接直接关闭；空闲读取 60 秒超时、写入 10 秒超时。每条 PHP 消息执行最多 10 秒，返回正文最多 4 MiB。应用返回数据，不能直接输出或设置 HTTP 响应头。

这是一种明确的 TCP 应用协议；如果业务使用二进制帧、长度前缀或连接握手，需要另外实现对应的 Go 协议适配器。

## gRPC：真实 Protobuf unary RPC

```powershell
go -C runtime run ./cmd/protocol-client --protocol=grpc --name=Ada
```

输出 RPC 状态、传输正文长度及 `"Hello, Ada"`。RPC 全名为 `/loom.examples.Greeter/SayHello`，请求和响应使用标准 `google.protobuf.StringValue`。契约位于 `proto/greeter.proto`，宿主和客户端都在运行时解析文件，不需要 protoc 或生成应用 Go 代码。网络使用标准 Protobuf 编码，Go/PHP 边界使用 ProtoJSON。

安装了 grpcurl 的环境也可以使用：

```sh
grpcurl -plaintext -import-path proto -proto greeter.proto -d '"Ada"' 127.0.0.1:18089 loom.examples.Greeter/SayHello
```

StringValue 的 Protobuf JSON 表示是字符串，故参数为 `"Ada"`。默认启用 v1/v1alpha 反射，也可以不传 `-proto` 查询服务：

```sh
grpcurl -plaintext 127.0.0.1:18089 list
grpcurl -plaintext 127.0.0.1:18089 describe loom.examples.Greeter
```

设置 `reflection: false` 可关闭反射；使用 `-proto` 的调用方式仍然有效。

## `.proto` 文件解析和动态注册

```powershell
go -C runtime run ./cmd/protocol-client --protocol=proto
```

显示 Greeter 的三个 RPC 及其请求、响应类型。编译器解析 `greeter.proto`、`common/greeting.proto` 和标准库 `google/protobuf/wrappers.proto`，完成语法、类型、字段和 import 检查。缺少文件、未定义类型、语法错误都会阻止启动，并返回文件位置相关诊断。

`proto_files` 可以选择多个入口文件；只有入口文件中声明的服务被注册，导入的消息可供编码、解码及反射使用。重复加载同一入口不会重复注册。修改协议后重启宿主即可生效。当前执行模型是 unary；发现流式 RPC 时明确拒绝启动。

新增 RPC 的完整路径是 `.proto` → 动态 Protobuf 请求 → `bootstrap/grpc.php` → `routes/grpc.php` → PHP Service → 动态 Protobuf 响应。PHP 收到：

```json
{"method":"/loom.examples.Greeter/Greet","request":{"name":"Ada","times":3}}
```

成功返回 `['data' => $response]`；业务失败返回 `['error' => ['code' => 'invalid_argument', 'message' => '...']]`。支持的业务错误码还有 `not_found`、`already_exists`、`permission_denied`、`unauthenticated`、`failed_precondition`、`unimplemented`。异常和不匹配协议的响应转换为 `Internal`。

参数遵循 ProtoJSON：字段使用 proto 原名，未设置的字段通常省略，64 位整数为十进制字符串，bytes 为 Base64，枚举通常为名称。示例 `times` 为 optional，可以区分省略（默认一次）和显式零（校验失败）。

## 自定义消息和 gzip 压缩

```powershell
# 嵌套 repeated 消息：生成三条问候
go -C runtime run ./cmd/protocol-client --protocol=grpc --method=Greet --data='{"name":"Ada","times":3}' --gzip

# 批量请求：输入 repeated string，返回 repeated Greeting 和 count
go -C runtime run ./cmd/protocol-client --protocol=grpc --method=BatchGreet --data='{"names":["Ada","Grace","Linus"]}' --gzip

# 业务错误：InvalidArgument，单次调用模式以非零退出码报告
go -C runtime run ./cmd/protocol-client --protocol=grpc --method=Greet --data='{"name":"Ada","times":0}' --gzip
```

Greet 返回 `{"greetings":[{"name":"Ada","message":"Hello, Ada"}, ...]}`。BatchGreet 返回三条问候和 `"count":3`，支持 1–1000 个名字。两种方法都调用共享 GreetingService。空名字、超过 100 字节的名字、非法次数及空批次都返回业务错误。

`--gzip` 启用标准 gRPC gzip 请求压缩，服务器按协商压缩响应；省略参数使用普通调用，两者可同时访问同一服务。输出的 `payload_bytes` 来自 gRPC 实际收发事件，统计压缩后的消息正文，不包含 HTTP/2 头和帧开销。小消息压缩后可能更大。

输入大小限制针对解压后的消息仍然有效；测试验证超过 1 MiB 的原始消息即使高度可压缩也返回 `ResourceExhausted`。这项功能只针对 gRPC，不改变 TCP 或 WebSocket 的消息格式。

## 一键完整演示

```powershell
./scripts/demo-protocols.ps1
```

脚本自动选择三个空闲回环端口，构建并启动独立演示宿主，使用 `storage/protocol-demo-随机ID/` 下的配置、演示数据库和日志，然后依次验证 HTTP、TCP、WebSocket、proto 解析、三个 RPC、错误响应及 gzip 对比。结束时停止本次创建的进程，日志与数据库保留供查看。原有配置和运行中的服务不变。先确保已按 README 完成运行时和 Composer 自动加载准备。宿主也支持 `--config` 指定其他配置文件。

已有宿主运行时使用：

```powershell
./scripts/demo-protocols.ps1 -UseRunningServer
# 或者只运行 gRPC 全套演示
go -C runtime run ./cmd/protocol-client --protocol=grpc --demo
```

演示分别以普通模式和 gzip 模式发送相同的 500 条重复名字批次，核对返回数量，并验证请求和响应的压缩正文均小于原始正文。成功时打印 `PASS: proto imports, dynamic methods, PHP dispatch, validation and gzip round trip`。单独调用可以通过 `--addr` 改地址，通过 `--proto-dir`、`--proto-file` 选择契约。

空名字或去除空白后超过 100 字节返回 `InvalidArgument`；未知 RPC 返回 `Unimplemented`；执行失败返回 `Internal`，客户端取消或执行期限到达使用对应 gRPC 状态，PHP 并发额度耗尽返回 `ResourceExhausted`。客户端默认设置 10 秒期限。服务器限制接收 1 MiB、发送 4 MiB、每连接最多 64 个并发流，PHP 执行器限制总并发调用为 64。

## 扩展自己的业务

1. 在 `app/Services` 写与协议无关的业务方法，通过普通参数接收输入并返回数据。
2. HTTP Controller 负责 HTTP 验证及状态码；TCP Handler 负责消息动作分发和业务错误；gRPC Service 实现对应 RPC 行为。
3. TCP 新动作在 `GreetingHandler` 的分发处接入，业务复杂后拆成多个 Handler，并在 `bootstrap/tcp.php` 装配。
4. 新 gRPC 服务先定义 `.proto` 并加入配置的 `proto_files`，然后在 `routes/grpc.php` 将完整 RPC 路径映射到 PHP 类和方法。服务类接收 ProtoJSON 解码后的参数，返回 data/error 信封。重启宿主后自动注册服务，无需改写 Go 描述符。
5. 通过 `loom.Config{Services: services}` 注入宿主共享资源；PHP 消息入口已加载 Loom 服务及 ORM API。不要把 HTTP Request、网络连接或 gRPC 上下文放入共享业务服务。

当前支持 `.proto` 驱动的 unary RPC 注册、显式 PHP 方法映射、反射和 gzip。服务端流、客户端流、双向流、TLS、认证和服务发现需要另行接入。

## 验证

```powershell
go -C runtime test ./... -count=1 -timeout 120s
```

测试使用临时端口，覆盖 TCP 同连接多消息与错误后恢复、关闭空闲连接、真实 gRPC 调用和状态码，以及消息 VM 隔离、取消、输入输出限制和禁止直接输出。HTTP/Studio 原有回归测试同时执行。
