# Loom 框架能力

首次阅读建议先看 [框架原理与 PHP 文件加载流程](framework-principles.md)，再按本文查阅各项能力。应用的组合方式、选项绑定和后台服务生命周期见 [宿主、应用与选项](hosting.md)，依赖注入见 [依赖注入与作用域](core-services.md)。

Loom 由 `framework/src/` 下的 Origami 脚本实现，`loom.php` 是唯一命令入口。框架直接调用标准库，应用使用 `App\` 命名空间。Go 不参与 Loom 应用装配和业务调用。

Loom 是具体应用项目，`framework/` 是随项目开发的内部框架，不是独立发布包。`framework/bootstrap.php` 只加载框架类。`loom.php` 加载它之后用顶层语句组合并运行应用；测试在闭包里 `require` `loom.php` 取得未运行的应用，或对临时项目根直接用 `Application::createBuilder()`。它们不注册命名空间，也不加载 `vendor/autoload.php`。`App` 与 `Generated\Grpc` 的类路径由宿主在执行脚本前写入 Origami。框架资源相对 `framework/` 定位，配置、业务与运行数据相对应用根目录定位。

## 实例服务与作用域

`Config`、`Database`、`Auth` 不再支持静态调用。服务在启动时登记到 `ServiceCollection`，在工作单元的 `ServiceScope` 里解析，见 [依赖注入与作用域](core-services.md)。

业务使用普通类型化构造参数；容器自动解析非内置类型，按非类名标识取服务时用 `Container\Annotation\Named` 或带 service 的 `Inject`。不要在业务类中注入容器再查找依赖。

```php
final class TaskService
{
    private \Loom\Auth $auth;
    public function __construct(\Loom\Auth $auth)
    {
        $this->auth = $auth;
    }
    public function tasks(): array
    {
        $user = $this->auth->guard();
        return \App\Models\Projects\Task::whereUserId($user['id'])->rows();
    }
}
```

Config 的根目录与缓存属于实例。Database 携带配置，不持有连接；事务只存在于 `transaction()` 回调。Auth 注入 Database 和 RequestContext。HTTP Kernel 绑定本次请求，退出时恢复先前上下文；后台代码只有显式绑定请求时才能调用需要 HTTP 身份的认证操作。`Request::capture/bind` 已移除。

应用模型继承 `Loom\Model`，通过 public 类型属性和 Origami 原生注解映射表结构。普通查询使用进程池，事务回调内新建的查询使用该事务，详见 [基础模型](models.md)。旧 EntityManager、EntityMetadata 和 Query 已移除。

HTTP 请求、TCP 消息、gRPC 调用、命令和队列、调度、定时器节拍各由宿主用 `withScope()` 开一个作用域，结束或抛错时释放。自建脚本用 `$app->services->withScope(...)` 执行工作，在 finally 里调用 `$app->dispose()` 释放单例。未结束的事务只可能存在于尚未返回的 `transaction()` 回调里。`Config` 是根容器上的实例，同一应用的作用域共用；不同应用应创建各自的 Config，运行中不要更换共享配置的根目录。

## 模块启动与注解注册

应用在 [loom.php](../loom.php) 里组合：`Application::createBuilder()` 得到构建器，往 `$builder->services` 登记，`build()` 得到 `Application`，再用 `use()`、`map*()`、`mapGroup()`、`mapCommand()`、`mapGrpc()` 映射中间件、路由、命令和 gRPC 方法。默认登记和各命令的运行方式见 [宿主、应用与选项](hosting.md)。

```php
$builder = \Loom\Application::createBuilder($root, $envFile);
// 框架默认登记单例 Loom\Cache；见 docs/cache.md
$builder->services->addScoped(RepositoryInterface::class, SqlRepository::class);
$builder->services->addTransient(HandlerInterface::class, Handler::class);
$app = $builder->build();
$app->mapGet('/health', static fn (): \Loom\Response => \Loom\Response::json(['ok' => true]));
$app->mapCommand('ping', static fn (\Loom\Console\Output $output) => $output->line('pong'), '应答');
```

`mapGroup($prefix, ...$middleware)` 返回 `RouteRegistrar`，可以继续 `prefix()`、`withMiddleware()`、`get()` 等；`RouteRegistrar::group()` 的回调参数是 `Router`。`Application::handle(Request)` 在新作用域里跑一次管道，测试用它代替网络请求。

`Router::match($method, $path)` 只返回 `RouteMatch`，不会创建控制器或执行中间件。`HttpKernel` 接受 `RouteMatcher` 接口，因此可独立替换匹配实现。全局中间件包裹 404、405 和路由 HttpError 响应；路由中间件在参数绑定之后执行，短路时不会解析后续处理器。WebSocket 连接在路由中间件放行之后才建立。

`ServiceCollection` 提供 `addSingleton($id, $implementation = null)`、`addScoped(...)`、`addTransient(...)` 和 `addInstance($id, $object)`，实现可以是类名或接收 `ServiceResolver` 的工厂。singleton 在根容器上只有一份，所有作用域共用，并发的工作单元会拿到同一份，对象自己必须能并发使用。scoped 每个作用域一份；未登记的具体类按 scoped 自动构造。transient 每次解析创建。不同应用、不同宿主进程之间不共享实例。

`Database` 和 `RequestContext` 由 `ApplicationBuilder` 登记为 scoped：`RequestContext` 保存当前请求，`Database` 保存这次工作的事务、命名连接和 `connect()` 改过的 DSN。连接池是宿主进程里的 `*sql.DB`，作用域不拥有连接。

同一标识可以登记多次，`get()` 取最后一条，所以应用的登记覆盖框架默认值。接口和实现类是两个标识，分别登记会得到两个独立服务；需要两个标识指向同一对象时，可先 `addSingleton(\Loom\Cache::class)`（构建器已 tryAdd），再用工厂转发：`addSingleton(CacheInterface::class, static fn (ServiceResolver $s) => $s->get(\Loom\Cache::class))`。目标没有登记时按 scoped 构造，单例工厂取它会报错。返回对象必须满足已知接口/类的类型要求。测试在 `require` `loom.php` 的闭包里先定义 `$configureServices = static function (ServiceCollection $services): void { ... }`，在构建前替换服务。

scoped 服务不能在根上解析，singleton 构造时也不能依赖 scoped 服务，否则抛出 `LogicException`。Go 侧锁保护单例构造，失败不缓存，后续解析可重试；单例自身的方法和可变业务状态仍需自行保证并发安全。实现 `Loom\Disposable` 的实例由创建它的作用域或根容器按相反顺序释放。

配置节绑定、后台服务的启停顺序，以及 `serve` 如何把定时器装进 Host，见 [宿主、应用与选项](hosting.md)。

模块服务使用 std 的 `Container\Annotation\{Singleton,Scoped,Component}` 与可选的 `Bind`：`Component` 映射为 `transient` 生命周期；仅 `Bind` 时默认 `scoped`。`name` 为空则用类名作为服务标识。`build()` 用 `tryAdd` 登记这些类，应用已经登记的同名标识优先。构造参数上的 `Container\Annotation\{Inject,Named}` 与 `InjectConfig` 属性由 `ConfigInjector` 注入。

`ModuleDiscovery` 负责扫描和声明校验，`ApplicationBuilder::build()` 消费这些定义，装配服务、路由、命令和定时器；任务交给 `JobDispatcher`。发现过程不会创建业务服务；定时器触发及实际请求执行时才解析实例。

在 `loom.php` 的 `build()` 之前用 `ApplicationBuilder::addModule()` 登记启动模块，例如 `$builder->addModule(\App\AppModule::class)`。多次调用可登记多个模块，同一类重复登记会去重；一次都不登记等价于以前 `config/modules.php` 返回空数组。模块用 `Loom\Attributes\Module` 声明命名空间与目录映射，可通过第二个参数导入其他模块：

```php
use Loom\Attributes\Module;

#[Module(['App' => 'app'], [])]
final class AppModule {}
```

`ApplicationBuilder::build()` 把 `addModule()` 登记的名单交给 `ModuleDiscovery::configured($config, $modules)`：按导入顺序加载模块、递归扫描 PHP 类、读取反射元数据并预校验声明路由，然后冻结，不可再添加组件。结果作为实例登记在根容器上，请求不再扫描目录。不同应用各自扫描，修改模块声明后重启应用。`build()` 之后不能再调用 `addModule()`。注解中间件须使用服务类名，在请求作用域内解析；需要闭包的路由在 `loom.php` 里用 `mapGet()` 等登记。重复导入/重叠扫描去重，循环导入、重复服务标识、重复任务类型和无效处理方法会报错。类与文件路径必须遵循对应命名空间的自动加载规则，扫描跳过符号链接；未声明组件注解的类不自动注册。模块类只声明元数据，不在构造器中执行启动副作用。

Loom 保留 `Module`、`Job`、`Listens`、`Timer`、`InjectConfig` 与逃生舱 `HttpRoute`；HTTP、容器与 CLI 标记使用 std 注解；数据库映射直接使用 `Database\Annotation`：

```php
#[\Net\Annotation\Controller]
#[\Net\Annotation\Route('/api')]
#[\Net\Annotation\Middleware(AuthMiddleware::class)]
final class UserController
{
    private UserService $service;
    public function __construct(#[\Container\Annotation\Inject] UserService $service)
    {
        $this->service = $service;
    }

    #[\Loom\Attributes\HttpRoute('GET', '/users/{id}', 'users.show')]
    public function show(\Loom\Request $request): \Loom\Response { /* 返回响应 */ }
}
```

HTTP 路由以 `Net\Annotation\Controller` 标记控制器；可选类级 `Route` 为路径前缀（默认 `"/"` 时等价空前缀）、可重复 `Middleware` 为类级中间件。常见方法使用 `GetMapping` / `PostMapping` / `PutMapping` / `DeleteMapping`。命名路由、PATCH/HEAD/OPTIONS、方法级中间件与 WebSocket 使用 `Loom\Attributes\HttpRoute`（与语言 Mapping 不能同方法共存）。发现阶段登记的动作为 `[控制器类, 方法名]`；`__invoke` 与单独的类名字符串注册都会被拒绝。类中间件先于 `HttpRoute` 方法中间件执行。HTTP 与 WebSocket 共用重复路径/名称校验；WebSocket 必须声明 GET。

构造参数上的 `Inject` / `Named` 按标识解析，空 `Inject` 按参数类型推断；同一参数不能同时声明 Inject、Named 与 InjectConfig。`loom.php` 里显式登记的同名标识优先于注解。不要使用属性提升参数声明注解，Origami 参数反射目前不完整。

模型直接使用 std 的 `Table`、`Column`、`Id` 和 `GeneratedValue` 注解，并继承 `Loom\Model`。字段类型由 public 属性声明，支持复合主键；查询条件可使用属性名或列名，数据库结构由迁移维护。

```php
$task = \App\Models\Projects\Task::create([
    'user_id' => $owner,
    'title' => '待验收',
]);
\App\Models\Projects\Task::whereUserId($owner)->whereId($task->id)
    ->update(['title' => '已验收']);
$stored = \App\Models\Projects\Task::whereUserId($owner)->findOrFail($task->id);
$data = $stored->toArray();
```

查询映射和回填委托语言层 ORM。更新显式指定字段；内存对象没有自动脏字段追踪，数据库回滚后应重新查询。`create()` 返回回填后的新对象，`insert($model)` 返回写入结果而不修改传入对象。原生行为差异及假值写入适配见 [基础模型](models.md)。

关系与归属限制由 `App\Repositories\Projects\ProjectRepository` 维护；`tasksFor($project)` 和 `projectFor($task)` 同时限制外键和 user_id。

容器是 Loom 自己的 `ServiceCollection` / `ServiceProvider` / `ServiceScope`，不使用 `Container\Container`，也没有进程全局容器。根容器缓存 singleton，每个作用域缓存自己的 scoped。Loom 负责生命周期选择、作用域依赖校验、ConfigInjector、工厂返回值验证与异常契约；普通类类型的构造参数自动装配，非类名标识使用 Inject，标量配置使用 InjectConfig 或工厂。

`Job($kind, $method = 'handle')` 根据持久化队列记录的 kind，由 `JobDispatcher` 分发到对应服务实例，处理方法接收 Request 并返回 Response。不同 kind 可以使用不同类，未知 kind 报错并由既有队列重试策略处理。隔离子进程、租约与幂等要求不变。

`Cli\Annotation\Command` 标记继承 `Loom\Console\Command` 的类，沿用 signature/description/handle 契约（不使用注解内的 name/description）。命令生成器自动添加此注解，闭包命令在 `loom.php` 里用 `$app->mapCommand()` 登记。

`Timer` 同样由模块发现，登记进单例 `TimerRunner`。支持固定秒间隔或五段 Cron（互斥）；Cron 使用与 `Schedule` 相同的 `Loom\Cron` 解析。`serve` 通过 `TimerHostedService` 把它接进 `Host`，在监听前启动、退出时停止。每次触发在新作用域里解析定时器；需要跨触发保留计数等状态的定时器登记为单例。忙碌时跳过节拍、不补跑、同一定时器不重叠的语义保持不变。启动顺序见 [宿主、应用与选项](hosting.md)。

各入口共享登记，而非共享业务实例：每个 HTTP 请求、TCP 消息、gRPC 调用、命令，以及队列、调度、定时器的每次节拍各开一个作用域。发现阶段不实例化控制器或服务，也不启动后台任务。手动 HTTP/WebSocket 路由写在 `loom.php` 里；与已声明的注解路由重名或重叠会报错。HTTP 启动时预先校验路由，修改注解或 `loom.php` 后需重启长期运行的进程。

## HTTP、路由与视图

`./build/loom.exe serve` 由 `HttpServer` 通过 `Net\Http\Server` 监听。路由表和中间件管道在 `build()` 和 `loom.php` 里装好。每个请求由 `HttpServer::handle()` 开一个作用域，用这张路由表匹配，再从该作用域解析控制器、中间件、`RequestContext` 和 `Database`。框架脚本在进程启动时加载，修改框架后需重启。

路由支持闭包、`[控制器, 方法]`、路径参数、命名 URL、嵌套分组与中间件。闭包的参数由 `Loom\Invoker` 绑定：`Request` 来自这次请求，其他类类型参数从请求作用域解析。Request 参数、属性及中间件声明保留既有脚本 API。HEAD 调用路由后抑制正文；404/405 使用 problem 响应，405 带 Allow。未捕获异常对客户端返回通用 500，细节写入 stderr。

`loom.php` 用 `$app->use(RequestLog::class)` 登记 `Loom\Http\Middleware\RequestLog` 全局中间件。它经根容器上的 `Loom\Logging\Diary` 写出 `http.request` 访问行（含 `correlation_id` 字段）。作用域级 SQL/HTTP 摘要由 `Loom\Monitoring\LoggingMonitoring` 在收尾时写出 `monitoring.session`，同样走 Diary 并携带同一 `correlation_id`，详见 [统一监控](monitoring.md)。`config/diary.php` 与 `DiaryOptions` 只提供默认；真实定制在 `$builder->diary` 的 `extend()`、`extendFormatter()`、`extendNamer()` 上完成。节缺失时默认 `writer=stack`（stderr 文本 + 按日 JSON 文件 `storage/logs/diary-{date}.log`，`{date}` 按 `timezone`，默认 `Asia/Shanghai`）。格式化器含 `json`、`text`、`pattern`；file 路径模板支持 `{date}`、`{level}`。未知 writer / formatter / namer 在 `build()` 绑定阶段拒绝。404、405、HttpError 和未捕获异常（500）都会记录；不记录请求头、查询参数或请求正文。由运行时直接处理的 `/assets/` 静态文件不经过应用中间件。测试仍可向 `RequestLog` 或 `LoggingMonitoring` 传入 callable 覆盖输出。

输入通过 `Request::json()` 与 `Input` 显式校验；`Request::form()`、`file()` 与 `files()` 解析 urlencoded 与 multipart 上传，见 [文件存储](filesystems.md)。应用内同步事件总线见 [应用事件](events.md)。视图使用隔离输出缓冲和 `$data` 参数，使用 `Loom\escape()` 转义。`public/` 内的文件通过 `/assets/` 提供，规范化路径必须仍在 public 内；`config/filesystems.php` 中 `serve => true` 的磁盘按各自 `url` 前缀对外提供文件（默认 `public` 磁盘为 `/files`）。不执行 `.php` 或公开配置、数据库。

脚本设置 10 秒协作执行限制、4 MiB 响应正文上限；通过 std 配置默认 1 MiB 请求体硬限制、网络超时、连接数及并发准入。请求体上限在脚本读取前执行并覆盖分块编码；WebSocket 也有消息上限和取消关闭。详细配置及边界见 [协议与网络限额](protocols.md)。

## 数据库、ORM 与迁移

`Loom\Database` 是一次执行的数据库上下文，由 `ApplicationBuilder` 登记为 scoped，每个作用域一份。`ServiceScope::run()` 在回调期间把它绑定到执行帧，模型静态查询使用这一份，见 [基础模型](models.md)。它使用 `Database\Sql` 的进程级连接池和 `Database\DB` 的查询。实例上保存当前事务、本次打开的命名连接和 `connect()` 改过的 DSN。普通语句结束即归还连接。`transaction()` 的事务只存在于该回调。会话级 `sql_mode` 和 `READ COMMITTED` 由 MySQL 驱动在物理连接建立时按 DSN 设置一次。作用域不持有连接，释放作用域也不关闭池。查询器要跑在 `*sql.Tx` 上时，使用语言层的 `Database\bind`。

- `query($sql, $args)` 返回关联数组；非 NULL 标量按字符串返回，模型再按声明恢复类型。
- `execute($sql, $args)` 返回整型 `id` 和 `affected`。`id` 来自这条语句的插入结果。
- `batch($statements)` 在同一事务执行；失败回滚。不支持嵌套事务。
- 参数通过占位符绑定，SQL 和标识符必须来自可信应用代码。
- `query/execute` 原样发送 SQL。`$database->conflict($unique, $update)` 只根据校验过的列名生成冲突子句。MySQL 冲突处理由唯一索引决定。
- 模型的普通读写走同一套池和事务。`native()` 与 `project()` 使用池中的原生查询器，不自动加入当前事务。

`framework/src/Database/Migrations.php` 负责校验和、版本前缀及批次；框架迁移位于 `framework/resources/migrations/`，应用迁移的命名空间和目录来自 `config/migrations.php`。`Migrations::migrate()` 先执行框架迁移再执行应用迁移，`serve`、`queue:work` 和 `schedule:work` 启动时各调用一次。迁移目录直接包含 MySQL SQL，以数据库命名锁串行执行；MySQL DDL 隐式提交，不能整体事务回滚。不能修改已发布迁移，`migrate:down` 逆序撤销最后一个应用批次。

迁移的重复执行、漂移检测、批次回退及失败后不登记版本的行为由框架测试验证；业务演示不提供执行迁移的 HTTP 入口。

## 缓存

业务侧同步缓存使用单例 `Loom\Cache`（`ApplicationBuilder` 经 `bindOptional` 绑定 `config/cache.php`）。JSON 信封存储、Redis 键前缀、监控钩子，以及与会话/队列继续使用 `Loom\Redis` 的边界，见 [缓存](cache.md)。

## 认证与会话

`framework/src/Auth/Auth.php` 实现注册、登录、退出、会话轮换、会话过期与限流。密码使用标准库 bcrypt；会话存于 Redis，键使用随机令牌的 SHA-256 摘要，TTL 为 24 小时，MySQL 账号版本用于事务性撤销。Cookie 使用 HttpOnly、SameSite=Lax，在 HTTPS 下启用 Secure。

写请求要求 CSRF，并校验 Origin 与实际请求来源一致；不信任代理头。注册和登录也校验 Origin。每个邮箱每分钟限制 10 次认证尝试。应用查询仍需明确约束当前用户，不提供隐式全局授权。

## 队列与调度

宿主显式加载 Origami 的可选 `std/php/redis` 模块。`Loom\Redis` 仅负责 URL 配置、前缀约定、作用域关闭及错误/空值映射，不再实现 RESP、网络连接、AUTH、TLS 或 JSON 桥接。`Redis::using($callback)` 提供一条原生连接，回调结束必定关闭；不把连接缓存到可能被请求或定时器复制的静态状态中。原生 `false` 空值映射为 Loom 的 `null`，命令错误则转为异常，不能混淆二者。

Redis Stream 是队列的投递源：`Queue::dispatch()` 在无事务时立刻 `XADD`，在 `Database::transaction()` 内则登记到提交后入队。延迟任务进入同槽位 `delayed` 有序集，到期由 worker Lua 原子迁入主 Stream。消费组 `loom`、每个 `queue:work` 进程独立 consumer 名；`XREADGROUP` → `_job` 子进程 → `XACK`/`XDEL`；`XAUTOCLAIM` 回收 PEL。失败按 `attempt²×2` 秒退避重入队，超过 3 次写入死信 Stream。`loom_jobs` 仅作运维归档，写入失败不影响 Stream 执行。业务 Lua 位于 `framework/resources/queue/`。

HTTP 仅入队，消费和调度独立运行：

```powershell
./build/loom.exe queue:work
./build/loom.exe schedule:work
```

`$queue->dispatch()` 支持最多 30 天延迟。至少一次投递；子进程上限 30 秒。应用事件 `queued: true` 监听器使用内置 `loom.event.listen` 任务种类，见 [events.md](events.md)。

每个作业通过 `$process->run()` 启动独立 Origami 进程，使用参数数组而不经 shell。任务输入通过临时文件传递，结束即删除；子进程执行 `loom.php _job <文件>`，在这条命令的作用域里交给 `JobDispatcher`。父进程限制执行 30 秒、输出合计 4 MiB。队列为至少一次交付，Studio 摘要通过唯一 job_id 实现幂等。

`$schedule->every()` 与 `$schedule->cron()` 注册调度，scheduler 在同一数据库事务内生成到期任务并推进执行时间。错过的固定间隔合并执行一次。同名、同周期重复注册不重置下次时间。

Cron 支持五字段数字、星号、范围、列表和步长，要求明确 IANA 时区。计算在绝对时间轴前进，正确区分 DST 重复时刻；默认时区转换依赖 doctor 验证通过的运行时。

长驻命令（`serve`、`tcp:serve`、`grpc:serve`、`queue:work`、`schedule:work`、`schedule:listen` 等）在阻塞前调用 `StopSignal::listen()` 监听 `SIGINT`/`SIGTERM`（Windows 上含 `SIGBREAK`）。收到停止信号时，框架**不做**内置排空：不等待进行中的 HTTP 请求、队列子进程、定时器 tick 或 HostedService 收尾；可选执行应用在 `ApplicationBuilder::onStop()` 或 `Application::onStop()` 登记的回调，然后以状态码 0 退出。未登记回调时同样直接退出。排空、租约与业务善后由应用在自己的回调里实现；队列未完成的租约仍可由既有回收机制处理。

## Console

`Application::run($args)` 为这条命令开一个作用域，交给 `Loom\Console\Console` 执行，结束时释放作用域。框架命令、模块命令和闭包命令共享 `Console` 注册表，统一执行、帮助、参数校验和 `Console::call()`；重名注册报错。输入错误返回 2，执行异常返回 1。

应用闭包命令在 `loom.php` 里用 `$app->mapCommand()` 登记，参数由 `Loom\Invoker` 绑定：`Input`、`Output` 来自这次调用，其他类类型参数从命令作用域解析。模块命令类由 `app/Console/Commands` 里的 `#[\Cli\Annotation\Command]` 发现，排在内置命令之后。`make:command` 等生成命令由 [ScaffoldCommands](../framework/src/Console/Commands/ScaffoldCommands.php) 登记，生成器不覆盖已有文件。

命令类的 `$signature`、`$description` 和可选 `$hidden` 必须在属性默认值中声明，不应在构造函数中设置。注册时读取元数据，不调用命令构造函数或解析业务依赖；执行并通过参数校验后才从命令作用域取得命令。`list` 和 `help` 不初始化数据库或启动服务。内部 `_job` 命令仍通过相同注册表执行，但不在列表中显示。嵌套 `Console::call()` 沿用外层命令的作用域，不提前释放外层命令的资源。

`framework/tests/` 存放不依赖 Studio 的框架测试，`tests/integration/` 存放依赖项目装配的 CLI、协议生成器及业务服务集成测试。`./build/loom.exe tests/run.php` 在独立 Origami 子进程运行这两类脚本测试；`tests/e2e/http.ps1` 验证网络业务链路。Go 宿主测试位于 `runtime/internal/host/`，通过 `scripts/build.ps1 -Test` 执行。
