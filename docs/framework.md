# Loom 框架能力

首次阅读建议先看 [框架原理与 PHP 文件加载流程](framework-principles.md)，再按本文查阅各项能力。

Loom 由 `framework/src/` 下的 Origami 脚本实现，`loom.php` 是唯一命令入口。框架直接调用标准库，应用使用 `App\` 命名空间。Go 不参与 Loom 应用装配和业务调用。

Loom 是具体应用项目，`framework/` 是随项目开发的内部框架，不是独立发布包。`framework/bootstrap.php` 只加载框架；`framework/bootstrap/autoload.php` 返回应用加载函数，由命令及测试入口传入项目根目录并创建作用域。它不注册命名空间，也不加载 `vendor/autoload.php`。`App` 与 `Generated\Grpc` 的类路径由宿主在执行脚本前写入 Origami。框架资源相对 `framework/` 定位，配置、业务与运行数据相对应用根目录定位。

## 实例服务与作用域

`Config`、`Database`、`Auth` 不再支持静态调用。应用加载函数返回 `ServiceScope`；引导脚本通过 `$scope->load($entry)` 执行，显式获得 `$scope`、`$services` 与 `$config`。直接 require 这些脚本不再是支持的入口。

业务使用普通类型化构造参数；容器自动解析非内置类型，服务别名仍可使用 `#[Inject('service.id')]`。不要在业务类中注入容器再查找依赖。

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

原生 HTTP、TCP、gRPC 和 CLI 入口自动在 finally 中关闭对应作用域。自建脚本应在 finally 中调用 `$scope->close()`，通过 ApplicationFactory 创建的应用可调用 `$app->close()`。关闭操作幂等，并清理请求上下文。未结束的事务只可能存在于尚未返回的 `transaction()` 回调里。配置对象可在同一应用的作用域间复用；不同应用应创建各自的 Config，运行中不要更换共享配置的根目录。

## 模块启动与注解注册

默认 HTTP 入口由 `Server` 创建请求 `ServiceScope`，通过 `load()` 执行 `framework/bootstrap/http.php` 装配应用。框架另提供 `ApplicationFactory`，适合显式组合模块与自定义路由：它复用模块声明，每次 `create()` 创建独立容器、数据库服务和路由表。不要将某次创建得到的服务实例捕获到供多个请求复用的注册回调中。

```php
$scope = (require $root . '/framework/bootstrap/autoload.php')($root);
$modules = $scope->services()->get(\Loom\ModuleRegistry::class)->configured();
$factory = new \Loom\ApplicationFactory($modules);
$factory->routes(static function (\Loom\Router $routes, \Loom\Application $app): void {
    $routes->get('/health', static function ($request) {
        return \Loom\Response::json(['ok' => true]);
    });
});
return $factory->create();
```

`Application::router()` 提供路由注册，`Application::kernel()` 提供 HTTP 执行入口，`services()` 提供当前应用容器。原有 `get/post/group/handle` 等便捷方法保留为委托入口。通过 Router 或 RouteRegistrar 注册分组时，回调参数为 `Router`；直接调用 `Application::group()` 时仍传入 Application。

`Router::match($method, $path)` 只返回 `RouteMatch`，不会创建控制器或执行中间件。`HttpKernel` 接受 `RouteMatcher` 接口，因此可独立替换匹配实现。全局中间件包裹 404、405 和路由 HttpError 响应；路由中间件在参数绑定之后执行，短路时不会解析后续处理器。WebSocket 连接在路由中间件放行之后才建立。

容器提供 `singleton($id, $implementation = null)`、`scoped(...)` 和 `transient(...)`，第二个参数统一接受实现类名或工厂。singleton 在同一应用根容器及其所有子作用域间共享；scoped 只在当前作用域复用；transient 每次解析创建。不同根容器、进程之间不共享实例。未显式注册的具体类默认 scoped，以保持请求隔离。旧 `bind()` 保留原有作用域共享行为，等同于 `scoped()`。

```php
$services->singleton(CacheInterface::class, MemoryCache::class);
$services->scoped(RepositoryInterface::class, SqlRepository::class);
$services->transient(HandlerInterface::class, Handler::class);
$services->scoped(Database::class, static fn (Container $c) => new Database($c->get(Config::class)));
```

接口绑定以服务标识为缓存键；分别注册接口和实现类，会得到两个独立服务。需要两个名称指向同一对象时使用 `alias($id, $target)`，它沿用目标生命周期。返回对象必须满足已知接口/类的类型要求。

`ServiceScope::fork()` 共享注册规则及 singleton，创建新的 scoped 缓存，不重复执行入口注册回调。`config/services.php` 在应用首次安装模块服务时执行一次。注册回调应只描述服务，不创建或捕获请求对象。`instance()` 注册调用方已构造的应用共享对象；`scopedInstance()` 为当前作用域绑定已有对象，框架用它安装 `ServiceScope`。

singleton 工厂及其依赖在根容器解析，直接或经 transient、alias 间接依赖 scoped 都会抛出 `LogicException`。Go 侧按应用串行保护单例构造，失败不缓存，后续解析可重试；单例自身的方法和可变业务状态仍需自行保证并发安全。`close()` 保留现有数据库、请求上下文清理行为，不会关闭其他作用域使用的 singleton，也不推断任意业务对象的析构协议。

根作用域另外注册两个 singleton：`Options` 和 `Host`。这是 .NET 通用宿主里的选项绑定和后台服务生命周期，接在现有 singleton、scoped、transient 之上。

`Options::bind($class, $section)` 把配置节的数组写进具体类的公开属性，并返回新对象。`null`、`false`、`0` 和空字符串原样保留；多余的键忽略。没有默认值的类型化属性必须存在，类型必须匹配，整数可以写入 `float`。属性类型是具体类且值是数组时递归绑定。类需要能无必填构造参数地创建。`Container::options($class, $section, $validate = null)` 把绑定结果注册为 singleton：第一次解析时绑定并执行可选校验，之后复用该对象；校验失败不缓存。直接 `bind()` 每次读取当前配置，已解析的选项对象不会随 `setRoot()` 更新。

`HostedService` 提供同步的 `start()` 和 `stop()`。`Host::service()` 在启动前登记实例或服务标识；启动时尚未注册的具体类会登记为 singleton，scoped、transient 以及会依赖 scoped 的 singleton 都会拒绝。`start()` 按登记顺序调用，中途失败则按相反顺序停止已经启动的服务，并抛出原来的异常。`stop()` 按相反顺序停止；某个 `stop()` 失败时其余服务仍会停止，然后抛出第一个停止异常。`run($work)` 包住一次启动、回调和停止。HTTP 服务在监听前把定时器运行器装进 `TimerHostedService`，和其他后台服务一起交给 `Host::run()`。队列和调度进程不启动这个 Host。`config/services.php` 在 `serve` 监听前执行，可以在那里登记后台服务或替换 `HttpServerOptions`。

`#[Loom\Attributes\Service(id: CacheInterface::class, lifetime: 'singleton')]` 可为模块服务选择生命周期，默认 `scoped`。`new Container($objectFactory)` 可替换对象创建策略，默认 `ConfiguredObjectFactory` 负责构造参数及配置属性注入。

`ModuleDiscovery` 负责扫描和声明校验，`ModuleRegistry` 消费这些定义，完成容器、路由、命令和任务装配。发现过程不会创建业务服务；定时器注册及实际请求执行时才解析实例。

`config/modules.php` 返回模块类列表，例如 `[\App\AppModule::class]`。模块用 `Loom\Attributes\Module` 声明命名空间与目录映射，可通过第二个参数导入其他模块：

```php
use Loom\Attributes\Module;

#[Module(['App' => 'app'], [])]
final class AppModule {}
```

`$modules->configured()` 首次按导入顺序加载模块、递归扫描 PHP 类、读取反射元数据并预校验声明路由。发现结果在当前应用根容器内共享，成功后不可再添加组件；后续调用直接复用，不重新扫描。不同应用根容器不共享缓存，修改模块声明后重启应用。注解中间件须使用服务类名，在请求容器内解析；需要实例或回调的路由通过作用域内的 `RouteProvider` 注册，避免缓存持有可变业务对象。重复导入/重叠扫描去重，循环导入、重复服务别名、重复任务类型和无效处理方法会报错。类与文件路径必须遵循对应命名空间的自动加载规则，扫描跳过符号链接；未声明组件注解的类不自动注册。模块类只声明元数据，不在构造器中执行启动副作用。

业务扩展注解位于 `Loom\Attributes`；数据库映射直接使用 std 的 `Database\Annotation` 注解，现有 `Loom\Timer`、`Loom\InjectConfig` 保持原接口：

```php
#[Controller('/api', [AuthMiddleware::class])]
final class UserController
{
    private UserService $service;
    public function __construct(#[Inject(UserService::class)] UserService $service)
    {
        $this->service = $service;
    }

    #[Route('GET', '/users/{id}', 'users.show')]
    public function show(\Loom\Request $request): \Loom\Response { /* 返回响应 */ }
}
```

`Controller` 的参数依次为路径前缀、中间件列表。`Route` 参数依次为 HTTP 方法、路径、路由名、中间件列表、类级路由的处理方法（默认 `__invoke`）、是否 WebSocket（默认 false）。方法级 Route 直接绑定所在方法。控制器中间件先于路由中间件执行，随后调用处理方法。HTTP 与 WebSocket 共用重复路径/名称校验；WebSocket 必须声明 GET。

`Service($id = '')` 注册容器服务，空 id 使用类名，非空 id 创建指向同一实例的别名。`Inject($id)` 标注普通构造参数，支持递归解析和依赖循环检测；同一参数不能同时声明 Inject 与 InjectConfig。显式 `config/services.php` 工厂优先。不要使用属性提升参数声明注解，Origami 参数反射目前不完整。

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

`Loom\Container` 是 `Container\Container` 的适配层。原生 singleton 工厂承担实例缓存与循环依赖检测；根容器缓存应用 singleton，每个请求/任务容器缓存自己的 scoped；不使用 `getInstance()` 进程全局容器。Loom 负责生命周期选择、作用域依赖校验、重复注册检查、ConfigInjector、工厂返回值验证与异常契约，普通类类型的构造参数自动装配，别名使用 Inject，标量配置使用 InjectConfig 或显式工厂。

`Job($kind, $method = 'handle')` 根据持久化队列记录的 kind 分发到对应服务实例，处理方法接收 Request 并返回 Response。不同 kind 可以使用不同类，未知 kind 报错并由既有队列重试策略处理。隔离子进程、租约与幂等要求不变。

`ConsoleCommand` 标记继承 `Loom\Console\Command` 的类，沿用 signature/description/handle 契约。命令生成器自动添加此注解，手写闭包命令由实现 `Loom\Console\CommandRegistrar` 的应用类注册。

`Timer` 同样由模块发现。HTTP 服务把它装成 `TimerHostedService`，由 `Host` 在监听前启动、退出时停止；每次 HTTP 请求只装配路由与服务，不启动定时器。固定周期、超时跳过、不补跑、同一定时器不重叠的语义保持不变。

各入口共享注册规则，而非共享业务实例：每个 HTTP 请求、队列子进程、协议调用分别创建容器，每个模块定时器使用独立容器，每次执行结束关闭该定时器的连接，处理器实例保留自身计数等状态，Console 使用命令容器。发现阶段不实例化控制器或服务，也不启动后台任务。手动 HTTP/WebSocket 路由通过 `Loom\Http\RouteProvider::register(Router $routes)` 注册，应用实现位于 `app/Http/ApplicationRoutes.php`，在 `loom.php` 中显式绑定；不能重复注册已声明的注解路由。默认 HTTP 引导和 `ApplicationFactory` 都使用当前应用容器解析该接口，未绑定时仅安装模块注解路由。HTTP 启动时预先校验路由，修改注解后需重启长期运行的进程。

## HTTP、路由与视图

`./build/loom.exe serve` 通过 `Net\Http\Server` 监听。标准库创建请求执行作用域；`Loom\Server` 读取请求、装配 Application、调用路由并写出 Response。每次请求重新装配应用容器。框架脚本在进程启动时加载，修改框架后需重启。

`framework/bootstrap/http.php` 返回 Application。路由支持闭包、可调用控制器、多方法控制器、路径参数、命名 URL、嵌套分组与中间件。Request 参数、属性及中间件声明保留既有脚本 API。HEAD 调用路由后抑制正文；404/405 使用 problem 响应，405 带 Allow。未捕获异常对客户端返回通用 500，细节写入 stderr。

HTTP 启动时默认注册 `Loom\Http\Middleware\RequestLog` 全局中间件，每次应用请求结束后向 stderr 输出一行 JSON，包含 UTC 时间 `time`、`event=http.request`、`method`、`path`、`status` 和毫秒耗时 `duration_ms`。404、405、HttpError 和未捕获异常（500）都会记录；不记录请求头、查询参数或请求正文。通过 `scripts/dev-server.ps1` 启动时可在 `storage/logs/dev-server.err.log` 查看。由运行时直接处理的 `/assets/` 静态文件不经过应用中间件。可通过构造参数传入接收日志字符串的 callable，自定义输出位置。

输入通过 `Request::json()` 与 `Input` 显式校验。视图使用隔离输出缓冲和 `$data` 参数，使用 `Loom\escape()` 转义。`public/` 内的文件通过 `/assets/` 提供，规范化路径必须仍在 public 内；不公开脚本、配置或数据库。

脚本设置 10 秒协作执行限制、4 MiB 响应正文上限；通过 std 配置默认 1 MiB 请求体硬限制、网络超时、连接数及并发准入。请求体上限在脚本读取前执行并覆盖分块编码；WebSocket 也有消息上限和取消关闭。详细配置及边界见 [协议与网络限额](protocols.md)。

## 数据库、ORM 与迁移

`Loom\Database` 使用 `Database\Sql` 的进程级连接池和 `Database\DB` 的查询。普通语句结束即归还连接。`transaction()` 的事务只存在于该回调。会话级 `sql_mode` 和 `READ COMMITTED` 由 MySQL 驱动在物理连接建立时按 DSN 设置一次。作用域不持有连接，关闭作用域也不关闭池。查询器要跑在 `*sql.Tx` 上时，使用语言层的 `Database\bind`。

- `query($sql, $args)` 返回关联数组；非 NULL 标量按字符串返回，模型再按声明恢复类型。
- `execute($sql, $args)` 返回整型 `id` 和 `affected`。`id` 来自这条语句的插入结果。
- `batch($statements)` 在同一事务执行；失败回滚。不支持嵌套事务。
- 参数通过占位符绑定，SQL 和标识符必须来自可信应用代码。
- `query/execute` 原样发送 SQL。`$database->conflict($unique, $update)` 只根据校验过的列名生成冲突子句。MySQL 冲突处理由唯一索引决定。
- 模型的普通读写走同一套池和事务。`native()` 与 `project()` 使用池中的原生查询器，不自动加入当前事务。

`framework/src/Database/Migrations.php` 负责校验和、版本前缀及批次；框架迁移位于 `framework/resources/migrations/`，应用迁移由 `framework/bootstrap/database.php` 装配。迁移目录直接包含 MySQL SQL，以数据库命名锁串行执行；MySQL DDL 隐式提交，不能整体事务回滚。不能修改已发布迁移，`migrate:down` 逆序撤销最后一个应用批次。

迁移的重复执行、漂移检测、批次回退及失败后不登记版本的行为由框架测试验证；业务演示不提供执行迁移的 HTTP 入口。

## 认证与会话

`framework/src/Auth/Auth.php` 实现注册、登录、退出、会话轮换、会话过期与限流。密码使用标准库 bcrypt；会话存于 Redis，键使用随机令牌的 SHA-256 摘要，TTL 为 24 小时，MySQL 账号版本用于事务性撤销。Cookie 使用 HttpOnly、SameSite=Lax，在 HTTPS 下启用 Secure。

写请求要求 CSRF，并校验 Origin 与实际请求来源一致；不信任代理头。注册和登录也校验 Origin。每个邮箱每分钟限制 10 次认证尝试。应用查询仍需明确约束当前用户，不提供隐式全局授权。

## 队列与调度

宿主显式加载 Origami 的可选 `std/php/redis` 模块。`Loom\Redis` 仅负责 URL 配置、前缀约定、作用域关闭及错误/空值映射，不再实现 RESP、网络连接、AUTH、TLS 或 JSON 桥接。`Redis::using($callback)` 提供一条原生连接，回调结束必定关闭；不把连接缓存到可能被请求或定时器复制的静态状态中。原生 `false` 空值映射为 Loom 的 `null`，命令错误则转为异常，不能混淆二者。

队列每轮在一个连接作用域内，通过原生 pipeline 同步最多 100 条待投递记录。消费组初始化、领取、发布和确认的业务 Lua 位于 `framework/resources/queue/`。网络与协议能力复用 Origami，租约、持久化投递和重试策略仍属于 Loom；当前依赖中的 Laravel Queue 模块只有空入口，不能当成可用队列实现。任务成功后的确认错误不会被当成处理器错误再次完成任务。

HTTP 仅入队，消费和调度独立运行：

```powershell
./build/loom.exe queue:work
./build/loom.exe schedule:work
```

`$queue->dispatch()` 支持最多 30 天延迟。队列仅支持 MySQL 存档 + Redis Stream：先写 MySQL 事务待投递记录，到期后通过 XADD 发布到 Stream，使用 XREADGROUP 领取、XACK 确认。XAUTOCLAIM 回收超过 60 秒未确认的消息；数据库租约过期后重新发布下一次尝试。最多 3 次尝试，失败退避分别为 2 秒和 8 秒。MySQL 保留任务历史，完成任务必须持有当前租约；旧 worker 不能覆盖新 worker 的结果。

每个作业通过 `$process->run()` 启动独立 Origami 进程，使用参数数组而不经 shell。任务输入通过临时文件传递，结束即删除；子进程执行 `framework/bootstrap/jobs.php`。父进程限制执行 30 秒、输出合计 4 MiB。队列为至少一次交付，Studio 摘要通过唯一 job_id 实现幂等。

`$schedule->every()` 与 `$schedule->cron()` 注册调度，scheduler 在同一数据库事务内生成到期任务并推进执行时间。错过的固定间隔合并执行一次。同名、同周期重复注册不重置下次时间。

Cron 支持五字段数字、星号、范围、列表和步长，要求明确 IANA 时区。计算在绝对时间轴前进，正确区分 DST 重复时刻；默认时区转换依赖 doctor 验证通过的运行时。

常驻命令依赖标准库和进程管理器处理生命周期；停止时不承诺旧宿主的完整排空语义，未完成任务依靠租约恢复。

## Console

`Loom\Console\ConsoleKernel` 负责控制台启动、命令注册和执行结束后的作用域清理；`Loom\Cli` 仅兼容旧的 `command` 参数前缀。框架命令、模块命令和闭包命令共享 `Console` 注册表，统一执行、帮助、参数校验和 `Console::call()`；重名注册报错。输入错误返回 2，执行异常返回 1。

应用闭包命令由 `app/Console/ApplicationCommands.php` 实现 `Loom\Console\CommandRegistrar::register(Console $console)`，并在 `loom.php` 的服务装配回调中通过 `$services->scoped(\Loom\Console\CommandRegistrar::class, \App\Console\ApplicationCommands::class)` 显式绑定。控制台从容器解析该接口并注册一次；未绑定时只加载内置命令和模块命令，不再隐式加载 `routes/console.php`。模块命令类仍由 `app/Console/Commands` 发现，`make:command` 注册在 `framework/bootstrap/commands.php`，生成器不覆盖已有文件。自定义启动入口或测试需要应用闭包命令时，应显式安装同一接口绑定。绑定在应用启动时注册，实例按作用域隔离。

命令类的 `$signature`、`$description` 和可选 `$hidden` 必须在属性默认值中声明，不应在构造函数中设置。注册时读取元数据，不调用命令构造函数或解析业务依赖；执行并通过参数校验后才通过容器取得命令。`list` 和 `help` 不初始化数据库或启动服务。内部 `_job` 命令仍通过相同注册表执行，但不在列表中显示。Kernel 在最外层命令结束时清理作用域，嵌套 `Console::call()` 不提前释放外层命令的资源。

`framework/tests/` 存放不依赖 Studio 的框架测试，`tests/integration/` 存放依赖项目装配的 CLI、协议生成器及业务服务集成测试。`./build/loom.exe tests/run.php` 在独立 Origami 子进程运行这两类脚本测试；`tests/e2e/http.ps1` 验证网络业务链路。Go 宿主测试位于 `runtime/internal/host/`，通过 `scripts/build.ps1 -Test` 执行。
