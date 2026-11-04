# Loom

开发者入门：[框架原理与 PHP 文件加载流程](docs/framework-principles.md)，从宿主启动、文件加载到一次请求的执行串联核心代码。配置绑定和后台服务见 [宿主与选项](docs/hosting.md)。

Loom 的项目目标是构建可扩展的通用型后台，为不同业务提供统一的管理工作台和可复用的基础能力。首页 `/` 是介绍这个后台的产品官网，用于展示产品定位、功能、使用场景、开发指南和体验入口。

## 产品目标

- 通用后台：围绕管理工作台建设账号与权限、菜单导航、业务数据管理、系统配置、操作审计和运行监控，支持按业务扩展模块。
- 产品官网：面向使用者和开发者介绍后台，提供快速开始和演示入口；首页不作为登录后的管理工作台。
- 现有基础：账号认证、项目与任务管理、数据库持久化、队列与调度、运行心跳和协议调用演示。现有 `/demo` 发布准备室保留为业务场景示例，项目与任务管理统一进入 `/admin`。
- 已完成通用模块：`/admin` 统一后台、用户管理、角色权限、分层菜单管理、系统配置、操作审计、邮箱验证和密码恢复。项目与任务、队列和周期调度已接入统一工作台。
- 后台核心能力：用户创建与详情、通用订单、全局队列、周期调度、站内/邮件/Webhook 通知、角色权限、菜单和运行监控。部署、渠道配置与接口说明见 [后台核心模块](docs/admin-operations.md)。
- 存储：MySQL 保存业务数据与任务历史，Redis 承担业务任务队列和登录会话。新库配置见 [MySQL 与 Redis](docs/mysql-redis.md)。

使用 Origami 脚本实现后台业务，同时在 `framework/` 中开发内部框架。Origami 是兼容 PHP 的语言；Loom 通过 `std` 使用 HTTP、WebSocket、TCP、gRPC、PDO、密码、时间和进程能力。认证、迁移、队列、调度、路由与命令均在脚本层实现。项目自有 Go 源码统一放在 `runtime/`，用于构建宿主、定制标准库装配和提供原生扩展；目录约定见 [runtime/README.md](runtime/README.md)。项目使用自定义宿主 `build/loom.exe` 运行脚本。

## 启动

根目录 `loom.php` 是命令入口，由 PHP 加载同目录 `.env`。首次配置可复制 `.env.example` 为 `.env` 并填写实际的 MySQL/Redis 连接信息；`.env` 已被 Git 忽略。优先级为 **`.env` > 进程环境变量 > 默认配置**，空值也会覆盖环境变量。修改后重启服务。

首次启动或执行 `./build/loom.exe loom.php migrate:up` 初始化空数据库时，自动创建超级管理员：邮箱 `admin@example.com`，密码 `1234567890`，登录入口 `/admin`。登录后请修改初始密码。已有后台账号时跳过创建；重复初始化不会重置密码、启用状态或角色。

从项目根目录启动：

```powershell
./scripts/build.ps1
./build/loom.exe serve
```

`loom.php` 的服务注册回调只在根作用域执行一次。子作用域共享这些注册规则和 singleton，scoped 服务各自创建。入口把命令、HTTP 和 gRPC 路由接口绑到应用实现，并把 `RequestContext`、`Database` 固定为 scoped。`Database` 由容器注入 `Config`，只携带配置，不占有连接。其他业务服务在 `config/services.php` 注册，不要重复注册已经占用的标识。

官网 `/`、后台 `/admin`、指南 `/guide` 和发布验收场景 `/demo` 共用服务。演示围绕一次发布的计划、验收、摘要交付与自动回顾展开，协议、队列和调度能力在这条业务流程中展示。旧工作室、协议实验室和 HTTP 实验页面地址统一跳转到 `/demo`。

- `resources/views/official/`：官网布局、首页与指南；对应 `public/official/` 资源。
- `resources/views/demo/`：演示站布局和功能页面；对应 `public/demo/` 资源。
- `frontend/vben-admin/`：Vben Admin 官方完整后台工程，使用 Element Plus 应用；构建产物位于 `public/admin/`，`/admin` 返回静态 SPA 入口。

两站分别使用展示型顶部导航与操作台侧边导航，不互相加载对方的 CSS/JS；官网进入演示站的链接在新标签页打开，演示站内部导航在当前标签页切换。演示站继续使用同一套业务 API、数据库和后台进程。

安装 Go 1.27.1 或更新版本、PowerShell 7 和 Git。在项目根目录构建宿主；所需 Origami 版本由 Go 模块固定并自动下载（见 [运行时要求](docs/runtime-requirements.md)）：

```powershell
./scripts/build.ps1 -Test
./build/loom.exe doctor
./build/loom.exe serve
```

同时启动 HTTP/WebSocket、TCP、gRPC、队列消费者与调度进程（通知推送需要 Redis，默认 `127.0.0.1:6379`；可通过 `LOOM_REDIS_URL` 配置）：

```powershell
./scripts/dev-stack.ps1
# 停止整组服务
./scripts/dev-stack.ps1 -Stop
```

默认 HTTP、TCP、gRPC 端口分别为 18087、18088、18089。管理后台提供项目、队列、周期调度与运行监控；发布验收场景展示报告及其实际 TCP/gRPC 调用证据。关闭页面不影响后台任务，日志位于 `storage/logs/dev-*.log`。

`/demo` 是端到端业务场景：无需注册创建独立体验空间 → 命名发布项目 → 完成三项验收 → 提交延迟 5 秒的队列任务 → 获取真实摘要 → 启用每 15 秒的自动回顾。页面展示数据库中的任务、报告与业务活动，支持刷新续用、暂停调度和下载不含会话令牌的 JSON 记录。体验会话有效期两小时；过期后后台任务会清理该空间的周期计划，不自动删除历史业务数据。它使用独立身份和 Cookie，不覆盖管理后台的登录。`tests/e2e/release-demo.ps1` 使用临时数据库验证完整流程、跨空间隔离、CSRF 和重复提交约束，已纳入 `tests/run-all.ps1`。

发布摘要的真实业务链路是 `queue:work → TCP release.snapshot → gRPC ReleasePolicy/Evaluate → studio_reports`。TCP 服务凭短期任务凭证读取所属空间的验收统计；gRPC 发布规则要求清单非空且全部完成。报告保存 TCP 返回的那次快照，而非重新在队列中统计。两种调用均通过实际网络连接，使用配置中的固定地址；当前演示在同机独立进程运行。gRPC 使用 `StringValue` 承载 JSON 业务载荷，契约见 `proto/release.proto`。服务不可用或规则拒绝时不交付摘要，队列最多尝试 3 次。页面按任务显示实际请求、响应及耗时，凭证不会进入展示记录。可在提交后的 5 秒窗口撤回验收，观察规则阻断；自动回顾也执行相同链路。

访问 http://127.0.0.1:18087/demo。配置按功能放在 `config/*.php`（如 `http.php`、`database.php`、`tcp.php`、`grpc.php`），可通过 `LOOM_ADDRESS` 和 `LOOM_DATABASE` 覆盖监听地址和默认 MySQL DSN（只接受 MySQL）。多个库写在 `config/database.php` 的 `connections`。MySQL 与 Redis 的凭据通过环境变量配置，见 [存储配置](docs/mysql-redis.md)。

### 定时器示例

在 `app/Timers/` 下创建类，在类上声明 `#[Timer(5)]` 并实现无参数的 `public __invoke()` 即可。框架在 `serve` 启动时自动递归发现并注册，不需要手写协程或注册配置。例如 `app/Timers/Heartbeat.php`：

```php
<?php
namespace App\Timers;

use Loom\Timer;

#[Timer(5)]
final class Heartbeat
{
    public function __invoke(): void
    {
        echo '[' . date('Y-m-d H:i:s') . "] [INFO] timer: service is running\n";
    }
}
```

仓库中的 Heartbeat 示例还会通过 `RuntimePulse` 将执行时间和本次启动的执行次数写入配置的数据库，供后台运行监控与发布验收诊断展示；上面仅保留最小日志示例。完成存储配置后运行 `./build/loom.exe serve`，首次在约 5 秒后输出：

```text
[2026-10-31 22:00:05] [INFO] timer: service is running
[2026-10-31 22:00:10] [INFO] timer: service is running
```

周期为正整数秒，以启动时间为基准按固定周期触发，同一定时器不会重叠执行。如果任务仍在执行，期间的触发直接跳过，不排队、不补跑；完成后等待下一个原定时间点。例如周期为 5 秒，任务在第 5 秒开始、第 12 秒结束，则跳过第 10 秒的触发，下一次在第 15 秒执行。每个定时器独立运行，等待不阻塞 HTTP 请求；异常写入标准错误后仍按原周期继续。类由容器创建，支持 `InjectConfig` 和 `config/services.php` 工厂绑定。类名、命名空间需与 `app/Timers/` 下的路径一致；未声明注解的类会被忽略，错误注解或处理方法会在启动时拒绝。

每个 HTTP 服务进程把这组定时器装进 `Host`，监听前启动，进程退出后停止，不需要队列或调度进程；多进程部署会各自执行，不提供分布式去重或任务持久化。修改类后重启服务；删除示例类或移除其注解即可关闭日志示例。其他后台服务的登记方式见 [宿主与选项](docs/hosting.md)。

后台消费和调度分别在另外的终端启动：

```powershell
./build/loom.exe queue:work
./build/loom.exe schedule:work
```

加 `--once` 可只执行一次。HTTP 不自动启动后台进程。停止进程后，未完成任务由租约过期恢复；任务需要保持幂等。

首次运行构建脚本后，开发时也可进入 `runtime/` 执行 `go run -modfile=go.local.mod ./cmd/loom serve`。构建脚本为固定版本 Origami 应用仓库内的执行上下文补丁，生成独立构建副本和被忽略的本地模块文件；详见 [运行时要求](docs/runtime-requirements.md)。联调已包含该能力的 Origami 源码时可指定 `-OrigamiSource` 或 `LOOM_ORIGAMI_SOURCE`，本机路径不写入提交的 Go 模块。构建产物不纳入版本控制；Linux/macOS 产物为 `build/loom`。原有 `build/zy.exe` 不再是默认运行程序。

Composer 只用于构建和代码风格工具：`composer build` 构建宿主（源码覆盖使用 `LOOM_ORIGAMI_SOURCE`）；`composer start`、`composer test`、`composer loom -- list` 调用上述 Origami 脚本。应用入口不加载 `vendor/autoload.php`。宿主在运行脚本前把 `app/` 和 `generated/grpc/` 写入 Origami 类路径，因此仓库内置应用无需先运行 Composer。部署需要携带匹配的运行程序、脚本和资源；目标机器无需 Go 工具链、Origami 源码或 `go.work`。Go 模块和构建入口位于 `runtime/`。

## 后台管理

后台采用独立前端工程，通过 JSON API 与后端交互。官方工程说明见 [Vben README](frontend/vben-admin/README.md)。当前使用官方 `apps/web-ele` 应用，核心布局、主题、样式与组件包保持上游代码；业务页面按官方系统管理示例的 Page、VxeGrid、Form、Drawer 接口接入。登录使用现有 PHP 邮箱账号和 HttpOnly Cookie，会话与 CSRF 从 `/api/admin/session` 获取，Mock 已关闭；`vben/123456` 演示账号不再适用。使用 Node 24.12.0（本地位于 `storage/tools/node-v24.12.0-win-x64`）和 pnpm 11.16.0。在 `frontend/vben-admin` 中执行 `pnpm install --frozen-lockfile`、`pnpm --filter @vben/web-ele build`；产物写入 `public/admin/`，访问 `/admin`。开发时在项目根目录运行 `./scripts/dev-vben.ps1`，访问 http://127.0.0.1:5777；该脚本会校验并重启上次启动的前端进程，`-Stop` 可停止，`-ApiTarget` 可指定 PHP 服务，默认 http://127.0.0.1:18087。修改后运行 `./scripts/dev-stack.ps1` 和 `./scripts/dev-vben.ps1` 重启后端与前端。


官网用户与后台管理员是独立账号体系。用户在 `/account` 注册；后台不开放注册。首次部署通过服务器命令创建管理员（密码通过 `LOOM_ADMIN_INITIAL_PASSWORD` 环境变量提供），再授予超级管理员角色：

```powershell
./build/loom.exe admin:create admin@example.com Administrator
./build/loom.exe admin:bootstrap admin@example.com
```

将邮箱替换为实际管理员邮箱。创建完成后清除初始密码环境变量。已有后台账号和历史业务数据保留，不会自动复制为官网用户。官网用户在独立的 `shop_customers` 表中保存，使用独立 Cookie，无法登录管理后台。`010_customer_identity` 迁移在启动时自动应用。

- 管理员：按姓名或邮箱搜索、修改姓名、分配多个角色、启停账号；停用会撤销登录会话与账户验证码。至少保留一名启用的超级管理员。
- 角色：创建、编辑、删除自定义角色，按模块授权。超级管理员和普通成员是不可编辑的内置角色；被用户使用的角色不可删除。授权人不能分配自己没有的权限。
- 菜单：编辑名称、站内地址、父菜单、顺序、可见权限与启用状态。拒绝外站地址和循环层级；隐藏父菜单会同时隐藏子菜单。菜单可见性之外，API 独立检查权限。
- 配置：后台名称、说明与公开注册开关即时生效。SMTP 凭据仅通过服务器环境变量配置，不存入浏览器可读设置。
- 审计：按操作或目标搜索，分页查看用户、角色、菜单、配置、登录与工作台操作。管理变更与审计在同一事务中写入；API 不提供审计修改和删除入口。
- 业务：管理自己的项目与任务，生成即时或延迟摘要，设置 Cron、暂停调度、查看队列并重试失败任务。管理员权限不取消项目与任务的账号隔离。

### 邮箱验证与账户恢复

`/admin` 的账户安全页面提供邮箱验证和密码重置；登录页提供忘记密码入口。邮件验证码有效期 30 分钟、单次使用，数据库仅存验证码哈希；同一邮箱同一用途每分钟最多生成一次。重置密码后撤销所有旧会话。发送成功后清除邮件正文，过期邮件由发送进程清理。

```powershell
$env:LOOM_SMTP_ADDRESS = 'smtp.example.com:587'
$env:LOOM_SMTP_FROM = 'noreply@example.com'
$env:LOOM_SMTP_USER = 'your-smtp-user'
$env:LOOM_SMTP_PASSWORD = 'your-smtp-password'
$env:LOOM_SMTP_TLS = 'starttls'
./build/loom.exe admin:mail
```

填写你实际使用的邮件服务信息。默认强制 STARTTLS；465 端口等隐式 TLS 服务设置 `LOOM_SMTP_TLS=implicit`。`plain-local` 仅允许回环测试服务器。传输有 15 秒总超时、60 秒租约，失败每分钟重试，最多尝试 5 次；`admin:mail --once` 处理一封并退出。未配置 SMTP 时不模拟发送成功，邮件保留待发送状态。配置了 SMTP 地址时，`scripts/dev-stack.ps1` 会同时启动邮件进程。

### 扩展业务模块

后台源码位于 `frontend/vben-admin/`，独立构建产物位于 `public/admin/`，服务端管理接口位于 `AdminController`，权限及管理事务位于 `App\Services\Access\Admin`。扩展模块时添加对应控制器和业务迁移，在 `Admin::permissions()` 注册权限，并在服务端调用 `Admin::require()`。再为前端模块配置 Vue 页面、路由元数据与菜单；仅隐藏前端入口不能替代服务端授权。

```powershell
./build/loom.exe tests/integration/loom_admin_test.php
./tests/e2e/admin.ps1
```

## PHP 代码格式化

开发机安装标准 PHP 8.1 或更新版本和 Composer，然后在项目根目录运行：

```powershell
composer format:install
composer format
composer format:check
```

`format:install` 根据 `tools/php-cs-fixer/composer.lock` 安装固定版本的 PHP CS Fixer；`format` 修复格式；`format:check` 只检查并显示差异，有未格式化代码时返回非零退出码，可用于 CI。格式化规则在 `.php-cs-fixer.dist.php`：PSR-12、4 空格缩进、LF 换行、字符串连接符两侧空格，禁用风险规则和语法升级规则。

工具依赖独立安装到 `tools/php-cs-fixer/vendor/`，不进入应用的 `vendor/autoload.php`，不需要安装到部署环境。格式化命令必须使用标准 PHP，不能用 `build/loom.exe` 执行工具。源码中的 Origami 原生类、注解和显式加载顺序保持不变。生成目录 `generated/`、构建与运行数据、依赖目录、`resources/views/` 混合 HTML 模板和 `tests/fixtures/`、`framework/tests/fixtures/` 测试夹具不参与自动格式化；模板和夹具中的空白或特殊语法可能是行为的一部分。源码生成器的字符串模板也不会作为独立源码格式化。

批量格式化后使用 Origami 验证行为：

```powershell
./scripts/test-storage.ps1 -Suites scripts
./tests/run-all.ps1
```

完整测试需要已构建的宿主和测试 Python 环境，准备方法见 `scripts/setup-tests.ps1`。新增 Origami 专有语法时先验证格式化器兼容性，必要时通过 Finder 的 `notPath` 排除该文件，避免开启 PHP 语法迁移或风险规则。

## 统一模块与注解

`config/modules.php` 声明启动模块，`app/AppModule.php` 用 `#[Module(['App' => 'app'])]` 指定扫描范围。模型、控制器、路由、服务、队列、定时器和命令共用 `ModuleRegistry`，无需各自维护扫描代码。

- `#[Controller]` 配合类或方法上的 `#[Route('GET', '/demo')]` 自动注册 HTTP 路由；发布验收页面使用该入口。控制器支持前缀和中间件，Route 支持命名和额外中间件，WebSocket 也走同一注册链路。
- `#[Service]` 注册服务；`#[Service('alias')]` 声明服务别名；构造参数用 `#[Inject(ServiceClass::class)]` 或 `#[Inject('alias')]` 注入。`InjectConfig` 继续用于配置注入。
- 模型直接使用 std 的 `Database\Annotation\Table`、`Column`、`Id`、`GeneratedValue`；字段类型取自属性声明，所有已映射字段均可写入，无需声明字段白名单，实体使用 public 强类型属性；可继承 `Loom\Model`，直接使用 `User::whereName("TEST")->first()` 等原生 ORM 快捷入口，详见 [基础模型](docs/models.md)。连接由框架根据配置自动初始化，与 PDO 共用事务；应用统一使用基础模型入口，旧 EntityManager 已移除；业务通过模型操作数据，必要的并发 SQL 封装在对应模型方法中。
- `#[Job('studio.digest')]` 声明队列任务类型，默认调用 `handle(Request): Response`；不同任务不再共用一个 `job_handler`。
- `#[Timer(5)]` 声明固定周期任务；`#[ConsoleCommand]` 标记继承 `Loom\Console\Command` 的命令类，`make:command` 自动生成该注解。

除原生数据库注解、`Loom\Timer`、`Loom\InjectConfig` 外，上述注解都在 `Loom\Attributes` 命名空间。现有应用类已迁移；具体用法和生命周期见 [框架文档](docs/framework.md)。`routes/*.php` 和 `config/services.php` 保留作为显式扩展入口，重复路由会报错，显式工厂优先于服务注解。

容器实例缓存、工厂执行与循环依赖检测复用 Origami 的 `Container\Container`；每个 Loom 容器持有独立原生容器，配置注入和显式工厂规则由适配层保留。当前 std 的复用边界见 [架构说明](docs/architecture.md)。

## PHP 配置

运行配置按功能拆分为 `http.php`、`database.php`、`console.php`、`jobs.php`、`tcp.php`、`grpc.php`、`migrations.php` 和 `network.php`。旧 `config/loom.json` 已移除；旧调用 `get('loom.address')` 改为 `get('http.address')`。数据库配置与 Laravel 相同：`get('database.default')` 是默认连接名，`get('database.connections')` 是命名连接。更新配置后重启常驻服务。

应用初始化时自动加载 `config/` 直属目录下的 `*.php`，以文件名（不含扩展名）作为配置名称。例如 `config/payment.php`：

```php
<?php
return ['timeout' => 30, 'retry' => ['enabled' => true]];
```

通过统一接口读取：

```php
$config = $app->container()->get(\Loom\Config::class); // 装配入口获取；业务代码使用构造器注入
$config->get('payment'); // 整个文件返回值
$config->get('payment.retry.enabled'); // true
$config->get('payment.timeout', 10); // 缺失时返回默认值
$config->get('http.address'); // config/http.php 的监听地址
$config->get('database.default'); // 默认连接名
$config->get('database.connections.mysql'); // Laravel 格式的 mysql 连接
$config->get(); // 返回以文件名为键的全部配置
```

只有不存在的键才使用默认值；配置中的 `null`、`false`、`0` 均原样返回。强类型配置用 Options 绑定，见 [宿主与选项](docs/hosting.md)。PHP 配置在每次应用初始化时加载并缓存，修改后应重启长期运行的服务。配置文件应直接返回数据，不要在加载过程中读取其他配置；文件名和数组键避免使用点号。没有保留文件名；`loom.php` 也只会作为普通配置加载。环境变量由各 PHP 配置文件读取，并随配置一起缓存。`config/services.php` 的闭包也会被加载，由 HTTP、Console、队列、定时器及协议入口通过统一模块注册中心调用来注册服务。

### 注解注入配置

由容器创建的服务、控制器和中间件支持用 `Loom\InjectConfig` 注解实例属性：

```php
<?php
namespace App\Services;

use Loom\InjectConfig;

final class PaymentService
{
    #[InjectConfig('payment.timeout', 10)]
    private int $timeout;

    #[InjectConfig('payment')]
    private array $settings;

    public function timeout(): int { return $this->timeout; }
}
```

应用初始化后，通过 `$app->container()->get(\App\Services\PaymentService::class)` 获取服务即可；路由中使用控制器类名时也会自动注入。`bind()` 工厂返回的对象同样经过注入。框架支持 public、protected、private 及继承属性，配置键使用与 `$config->get()` 相同的点号格式。

属性注入发生在构造函数/工厂返回之后、容器缓存实例之前。直接 `new` 的对象不会自动注入。属性注解用于实例属性，不用于静态属性；不要把属性注入标注在已经初始化的 readonly 属性上。类文件应通过应用自动加载或在框架初始化后加载，保证注解类已注册。

未提供第二个参数时，配置缺失会抛出包含配置键和目标属性的异常；显式传入 `null` 可作为默认值。配置中的 `null`、`false`、`0` 不会被默认值替换。属性类型不兼容、重复注解也会报错，注入失败的对象不会被容器缓存。已缓存对象不会因配置变化而重新注入，修改配置后应重启服务。

构造函数中需要配置时，可以将注解标在普通构造参数上：

```php
use Loom\InjectConfig;

final class ApiClient
{
    private int $timeout;

    public function __construct(
        #[InjectConfig('payment.timeout', 30)] int $timeout,
        string $name = 'default'
    ) {
        $this->timeout = $timeout; // 构造函数执行时配置已可用
    }
}

$client = $app->container()->get(ApiClient::class);
```

容器自动创建对象时先解析构造参数，再调用构造函数，最后执行属性注入。继承的构造函数也支持此流程。带注解参数使用配置值，缺失时使用注解的第二个参数；未指定注解默认值则报错，不回退到参数声明的默认值。未带注解的可选参数保留声明默认值；未带注解的必填参数需要通过 `bind()` 工厂传入，容器不会猜测其依赖。工厂绑定优先，框架不会重新调用工厂返回对象的构造函数。

当前 Origami 的参数反射不暴露属性提升参数和可变参数的注解，因此配置参数请使用示例中的普通参数写法，不要使用 `#[InjectConfig(...)] public int $timeout` 或 `...$values`。构造函数抛出的异常和参数类型错误会向上传播，失败实例不会被缓存。

## 目录

```text
app/                  业务控制器、模型、服务、任务
runtime/              Go 模块、自定义宿主、标准库装配与原生扩展
framework/
  src/                按职责拆分的内部框架模块
  bootstrap/          自动加载、HTTP、命令、数据库和协议启动
  resources/          框架版本化 SQL 迁移
  tests/              框架契约与运行时回归测试
  bootstrap.php       独立框架模块加载入口
loom.php          项目命令入口
config/               手写配置与服务绑定
routes/               业务路由和命令注册
proto/                对外协议契约
generated/           可重新生成的 gRPC 基类与注册清单（提交）
database/migrations/  业务 SQL 迁移
frontend/vben-admin/       独立后台前端：Vue 3、TypeScript、Vite、Element Plus
resources/views/      official/ 官网；demo/ 演示站
public/               admin/ 后台资源；official/ 官网资源；demo/ 演示站资源
tests/integration/    项目装配、ORM 和生成器测试
tests/e2e/            HTTP、请求隔离、协议与网络限额测试
tests/fixtures/       独立测试应用与协议探针
scripts/              开发服务与测试环境安装工具
storage/              database、logs、run、testing、archive
build/                本机运行程序等构建产物（不提交）
.venv/                Python 测试依赖（不提交）
docs/                 当前设计与使用说明；history 存历史方案
```

## 命令与测试

```powershell
./build/loom.exe list
./build/loom.exe help projects:tasks
./build/loom.exe migrate:up
./build/loom.exe migrate:status
./build/loom.exe projects:tasks 1 --json
./build/loom.exe reports:dispatch 1 --delay=60
./build/loom.exe make:command SendDigest --command="mail:digest {user}"
./scripts/test-storage.ps1 -Suites scripts
./tests/e2e/http.ps1
```

`migrate:down` 撤销应用最后一个迁移批次，会执行对应 down SQL。HTTP、worker 和 scheduler 启动时自动初始化数据库并应用待执行迁移。

测试覆盖路由、中间件、视图、命令、数据库、认证、ORM、迁移、队列租约、调度和 Cron。端到端测试启动项目宿主执行脚本，验证真实 HTTP、独立作业进程、请求隔离和 WebSocket；结束时关闭自己启动的服务并清理独立测试数据。

## 当前边界

HTTP、WebSocket、TCP、gRPC 和后台业务核心链路均通过脚本启动。协议分别运行：

```powershell
./build/loom.exe tcp:serve
./build/loom.exe grpc:serve
./build/loom.exe proto:check
```

创建新协议可执行 `./build/loom.exe make:proto Invoice --package=app.billing`，编辑后运行 `proto:check`，再用 `make:grpc proto/invoice.proto --dry-run` 预览并生成。生成器保留手写服务并提示待实现方法；完整流程见 [协议开发说明](docs/protocols.md)。

TCP 使用 JSON 行协议；gRPC 支持 unary RPC、动态 proto imports、反射及 gzip，生成器保留业务文件。网络限额由脚本配置、通用 std 执行，包含请求体、消息大小、连接数、并发准入和超时。应用 streaming RPC、TLS 终止及统一优雅停机编排不在当前实现内。参见 [协议与限额](docs/protocols.md)。

## 完整验证与开发工具

```powershell
./scripts/setup-tests.ps1
./tests/run-all.ps1
./scripts/dev-server.ps1
./scripts/dev-server.ps1 -Stop
```

`scripts/test-storage.ps1 -Suites scripts` 为每个框架与项目脚本测试创建独立 MySQL 数据库和 Redis 前缀；`tests/run-all.ps1` 在临时 MySQL/Redis 服务上运行完整脚本、HTTP、后台任务、通知及 TCP/gRPC、网络限额测试。可用 `-MySqlBin`、`-RedisBin` 指定服务程序目录。开发工具要求 PowerShell 7。`generated/` 随部署交付，修改 proto 后用 `make:grpc` 更新并提交生成结果；不要手改生成基类。

目录边界和数据迁移约定见 [当前架构](docs/architecture.md)。
