# Loom

面向 Origami 运行时的独立 PHP 全栈框架。框架核心、Go 宿主和 PHP 应用分层维护；Studio 是内置的真实业务应用，覆盖认证、项目与任务、ORM、版本化迁移、队列、Cron 和脚本命令。

## 本地启动

需要 Go 1.27.1 或更高版本，以及包含 Loom 所需 PHP 能力的 Origami 源码。当前使用本地运行时开发依赖，尚未声明可独立下载的 Origami 发布版本。

```powershell
cd D:\github.cocm\php-any\loom
./scripts/setup.ps1 -OrigamiPath D:\gitcode.com\origami
composer dump-autoload
composer start
```

访问 http://127.0.0.1:18087/studio。监听地址、数据库和 PHP 入口集中在 `config/loom.json`；命令行选项可覆盖地址、数据库和 HTTP 入口。默认同时启动 TCP（18088）和 gRPC（18089）示例服务。此项目不需要 `-mod=readonly`。

`runtime/go.work` 只记录本机运行时路径，已忽略，不应提交。项目不依赖 Origami 的 examples 或 std/loom 目录。运行时的兼容性修复仍在 Origami 仓库维护。

根目录以 `composer.json` 作为 PHP 项目入口。安装 Composer 后可用 `composer start` 启动、`composer test` 测试、`composer build` 构建，以及 `composer loom -- command list` 执行命令。当前脚本调用 Origami 的 Go 宿主，仍需安装 Go。首次运行前执行 `composer dump-autoload` 生成自动加载文件；HTTP、终端和队列入口统一加载 `bootstrap/autoload.php`。

## 目录职责

```text
loom/
├── composer.json         PHP 项目元数据与开发命令
├── runtime/              独立的 Go 模块（go.mod、go.sum）
│   ├── cmd/loom/         Go 命令行入口
│   ├── internal/host/    服务启动、配置、静态资源与工作进程装配
│   └── framework/        Go 框架包及测试，php/ 内嵌 PHP 框架 API
├── app/                  应用业务
│   ├── Http/Controllers/ HTTP 页面与 API 控制器，一类一文件
│   ├── WebSocket/Handlers/ WebSocket 连接处理器
│   ├── Tcp/Handlers/     TCP 消息处理器
│   ├── Grpc/Services/    gRPC 应用服务实现
│   ├── Services/         可复用的业务服务
│   ├── Models/           ORM 模型
│   ├── Console/Commands/ 命令类
│   ├── Jobs/             队列业务
│   └── Support/          应用辅助逻辑
├── bootstrap/            HTTP、TCP、gRPC、终端、队列启动脚本
├── routes/               Web、Studio API、WebSocket 与终端命令注册
├── proto/                gRPC Protobuf 服务契约
├── config/               宿主配置
├── database/migrations/  不可修改的已发布 SQL 迁移
├── resources/views/      PHP 视图与布局
├── public/               唯一公开的静态资源目录
├── storage/              数据库及运行产物，不提交用户数据
├── tests/php/            PHP 契约与业务回归
├── scripts/              开发环境脚本
└── docs/                 框架能力说明
```

根目录仅存项目元数据和说明。PHP 入口只负责装配，业务代码不放在入口文件中。Go 框架包的导入路径为 `github.com/php-any/loom/framework`，包名为 `loom`。

应用类使用 `App\` 命名空间，通过 Composer PSR-4 映射到 `app/`，类名与文件名一致。控制器支持 `[Controller::class, 'method']` 多方法路由，也兼容 `__invoke` 单动作控制器；无需强制实现接口。`routes/` 只负责路由注册，依赖工厂在启动层装配。

静态资源和 SQL 迁移从应用目录读取，修改后不需要重新编译；PHP 框架源码内嵌在框架包中，修改框架后需要重启宿主。只有 `public/` 会通过 `/assets/` 暴露。

## 命令与迁移

```powershell
go -C runtime run ./cmd/loom --root .. command list
go -C runtime run ./cmd/loom --root .. command help studio:tasks
go -C runtime run ./cmd/loom --root .. command studio:tasks 1 --json
go -C runtime run ./cmd/loom --root .. command studio:digest 1 --dry-run
go -C runtime run ./cmd/loom --root .. command make:command SendDigest --command="mail:digest {user}"
go -C runtime run ./cmd/loom --root .. --migrate=status
go -C runtime run ./cmd/loom --root .. --migrate=up
```

宿主选项放在 `command` 前；PHP 命令选项放在命令名后。命令运行不启动 HTTP 服务。队列消费依赖正在运行的工作进程。`--migrate=down` 撤销最后一个迁移批次，使用前应备份业务数据。

自定义脚本命令写在 `routes/console.php`，命令类位于 `app/Console/Commands/`。详细签名和 API 见 [框架文档](docs/framework.md)。

## 测试与构建

```powershell
go -C runtime test ./... -count=1 -timeout 120s
go -C runtime test ./framework -run TestPHPContracts -count=1
go -C runtime build -o ../build/loom.exe ./cmd/loom
./build/loom.exe --root .
```

完整测试涵盖框架、真实业务链路、TCP/gRPC 网络调用和 PHP 契约，不需要另一个长期运行的服务。测试中的临时监听器自动关闭。部署可执行文件时，仍需携带 app、bootstrap、routes、config、proto、database、resources、public 目录以及 Composer 生成的 vendor 自动加载文件；从其他目录运行二进制时通过 `--root` 指定项目目录。

## 多协议示例

新增 gRPC 服务只需定义协议、生成骨架、填写业务方法：

```powershell
composer loom -- make:grpc proto/user.proto
```

命令自动维护协议和路由注册信息；业务方法直接返回结果，通过 `Loom\Grpc\RpcException` 表达业务错误。重复生成只更新基类和注册清单，保留已有业务文件。仓库包含可运行的 UserService 示例，完整演示脚本也会调用它。

HTTP、WebSocket、TCP 和 gRPC 的入口按协议分目录，共享 `app/Services/GreetingService.php`。Go 宿主负责监听、协议解码与生命周期，PHP 负责应用行为。客户端、预期返回、错误示例及新增服务步骤见 [多协议开发指南](docs/protocols.md)。

`./scripts/demo-protocols.ps1` 会启动使用独立数据库的演示宿主，运行全套示例并在结束时停止该进程。已有服务可加 `-UseRunningServer`。gRPC 在启动时解析 `.proto` 文件及 import，自动注册 unary RPC，通过 `routes/grpc.php` 分发到 PHP；支持反射和 gzip 压缩，无需生成 Go 消息代码。

```powershell
# 另一个终端中执行；先用 composer start 启动宿主。
go -C runtime run ./cmd/protocol-client --protocol=tcp --name=Ada
go -C runtime run ./cmd/protocol-client --protocol=grpc --name=Ada
go -C runtime run ./cmd/protocol-client --protocol=websocket --name=Ada
go -C runtime run ./cmd/protocol-client --protocol=proto
go -C runtime run ./cmd/protocol-client --protocol=grpc --demo
```

## 当前边界

支持复用 Origami 标准库的 WebSocket 路由，示例端点为 `/ws/echo`；路由、连接限制及生命周期说明见 [WebSocket 文档](docs/framework.md#websocket)。

当前使用 SQLite，保留显式容器工厂和请求级 RequestVM 隔离；暂不提供关系预加载、完整 Policy、交互式终端组件或生产级多实例调度保证。独立项目不代表这些能力已全部完成。
