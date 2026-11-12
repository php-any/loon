# 宿主、应用与选项

Loom 的启动方式照 ASP.NET Core 的 `WebApplication`：`Application::createBuilder()` 得到构建器，往 `services` 登记，`build()` 得到应用，再映射中间件、路由、命令和 gRPC 方法，最后 `run()`。依赖注入和作用域见 [依赖注入与作用域](core-services.md)。

## 入口

[loom.php](../loom.php) 就是组合根，对应 `Program.cs`：主机、服务生命周期、中间件、映射和运行都写成顶层语句。

```php
require_once __DIR__ . '/framework/bootstrap.php';

$entry = realpath($_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__);
$builder = Application::createBuilder(__DIR__, $entry ? (getenv('LOOM_ENV_FILE') ?: __DIR__ . '/.env') : null);
$builder->services->addSingleton(CheckoutOffer::class, static function (ServiceResolver $services): CheckoutOffer { ... });
if (isset($configureServices)) {
    $configureServices($builder->services);
}

$builder->addModule(\App\AppModule::class);
$app = $builder->build();
$app->use(RequestLog::class);
$app->mapGrpc('/loom.examples.ReleasePolicy/Evaluate', [ReleasePolicyService::class, 'evaluate']);
$app->mapCommand('projects:tasks {user}', static function (Input $input, Output $output): int { ... });

if (!$entry) {
    return $app;
}
try {
    $code = $app->run(array_slice($argv, 1));
} finally {
    $app->dispose();
}
exit($code);
```

直接执行时读取 `LOOM_ENV_FILE` 或项目根的 `.env`，执行一条命令后 `dispose()`。被 `require` 时不读 `.env`（它会覆盖进程环境变量），返回构建好的应用，由调用方负责 `dispose()`。测试这样取得应用：

```php
$app = (static function () {
    $configureServices = static function (ServiceCollection $services): void { /* 替换服务 */ };
    return require dirname(__DIR__, 2) . '/loom.php';
})();
```

闭包把 `loom.php` 的顶层变量和测试隔开；`$configureServices` 可省略。临时项目根没有 `loom.php` 时直接用 `Application::createBuilder($root)`。

## ApplicationBuilder

构造时加载环境文件和 `config/`，登记框架默认服务：

- 实例：`Config`。
- 单例：`Options`、`Network`、`Process`、`Host`、`HttpServer`、`TcpServer`、`GrpcServer`、`JobDispatcher`；`HttpServerOptions` 绑定 `config/http.php`。
- 后台服务：`TimerHostedService`。
- scoped：`Database`、`RequestContext`、`Loom\Monitoring\CorrelationId`。

在 `build()` 之前用 `addModule(\Some\Module::class)` 登记启动模块；`build()` 扫描这些模块：

- 带 `#[Service]` 的类按注解的生命周期 `tryAdd`，应用已登记的标识不覆盖。
- 控制器上的 `#[Route]` 进入路由表。
- `#[ConsoleCommand]` 类进入命令表，排在内置命令之后。
- `#[Timer]` 类交给 `TimerRunner`。
- `ModuleDiscovery`、`Router`、`HttpKernel`、`Console`、`Grpc\Dispatcher` 作为实例登记，可以注入。

## Application

| 方法 | 对应 ASP.NET Core |
| --- | --- |
| `use($middleware)` | `app.Use(...)` / `UseMiddleware<T>()` |
| `map` / `mapGet` / `mapPost` / `mapPut` / `mapPatch` / `mapDelete` | `MapGet` 等 |
| `mapWebSocket($path, $action)` | WebSocket 端点 |
| `mapGroup($prefix, ...$middleware)` | `MapGroup()` |
| `url($name, $parameters)` | `LinkGenerator` |
| `mapCommand($signature, $handler)` | — |
| `mapGrpc($method, [$class, $method])` | `MapGrpcService<T>()` |
| `handle(Request)` | `TestServer`，进程内跑一次管道 |
| `run(?array $args)` | `app.Run()`，这里执行一条命令 |
| `services` / `configuration` | `app.Services` / `app.Configuration` |

中间件可以是类名（每个请求从作用域解析）、`Middleware` 实例或 `(Request $request, callable $next): Response` 闭包，按登记顺序执行。路由处理器可以是 `[控制器, 方法]`、可调用类名或闭包。

映射方法只在启动阶段调用。`serve` 开始监听后，请求虚拟机里的修改不会留给其他请求。

## 各命令怎样运行

`Application::run()` 为这条命令开一个作用域。长驻命令在这个作用域里阻塞，每个工作单元再各开一个作用域：

| 命令 | 做什么 |
| --- | --- |
| `serve` | `doctor` 检查 → `Migrations::migrate()` → `Host::run(HttpServer::run)` |
| `tcp:serve` | `TcpServer::run()`，每条消息解析 `tcp.handler` |
| `grpc:serve` | `GrpcServer::run()`，每次调用交给 `Grpc\Dispatcher` |
| `queue:work` | 检查和迁移后，每秒一个作用域 `Queue::tick()` |
| `schedule:work` | 检查和迁移后，每秒一个作用域 `Schedule::tick()` |
| `_job` | 队列子进程：读任务信封，交给 `JobDispatcher` |

只有 `serve` 启动 `Host`，所以后台服务和定时器只在 HTTP 进程里运行。

## Options

选项类是普通的具体类。公开实例属性与配置节里的键同名，构造函数不能有必填参数。

```php
final class PaymentOptions
{
    public int $timeout;
    public bool $enabled = false;
}

$builder->services->configure(PaymentOptions::class, 'payment', static function (PaymentOptions $options): void {
    if ($options->timeout < 1) {
        throw new \LogicException('payment.timeout must be positive');
    }
});
```

`configure()` 登记一个单例：第一次解析时用 `Options::bind()` 读配置节并执行校验。校验抛错时不缓存，下次解析重试。业务代码注入 `PaymentOptions`，不要为了读配置再注入容器。

绑定规则：

- 配置节必须是数组；点分路径可以，例如 `payment.gateway`。
- 没有默认值的公开类型化属性必须出现在配置里。有默认值的可以省略。
- `null`、`false`、`0` 和空字符串原样写入；多余的键忽略。
- 类型按声明检查，整数可以写入 `float`，数字字符串不能代替数字或布尔值。
- 属性类型是具体类、值是数组时递归绑定。私有和受保护属性不参与。

单个标量仍可用 `InjectConfig`。

## 停止信号与 onStop

长驻命令在阻塞前让单例 `StopSignal` 监听停止信号。应用可在组合根登记退出回调：

```php
$builder = Application::createBuilder(__DIR__, $envFile);
$builder->onStop(static function (): void {
    // 可选：记录日志、触发排空、通知外部系统等
});
$app = $builder->build();
// 或在 build() 之后：$app->onStop(static function (): void { ... });
```

`ApplicationBuilder::onStop()` 与 `Application::onStop()` 可多次调用；回调按登记顺序累积，停止时依次执行（不会互相覆盖）。收到信号时的保证：`listen()` 已启用的进程中，先执行已登记的全部 `onStop` 回调（若有），再 `exit(0)`。不保证：框架不会等待进行中的请求或任务；`Host::stop()` 不会在信号路径上自动调用。`serve` 仍用 `Host::run()` 在正常返回时按相反顺序停止 HostedService；通过信号退出时进程直接结束。

## Host 与后台服务

后台服务实现 `Loom\HostedService`（`start()` / `stop()`），用 `addHostedService()` 登记为单例：

```php
$builder->services->addHostedService(\App\Workers\HeartbeatHost::class);
```

`Host::run($work)` 从根容器取 `getAll(HostedService::class)`：

- 按登记顺序 `start()`；重复调用直接返回。
- 某个 `start()` 失败时，已启动的按相反顺序 `stop()`，再抛出原来的异常。
- `stop()` 按相反顺序调用；其中一个失败时其余仍会停止，最后抛出第一个异常。
- 停止之后可以再次启动。

后台服务是单例，构造时不能依赖 scoped 服务。需要数据库时，每次执行用 `ServiceProvider::withScope()` 开作用域。

## 定时器

`#[Timer(秒)]` 类由 `TimerRunner` 按周期触发，[TimerHostedService](../framework/src/Hosting/TimerHostedService.php) 把它接进 Host。每次触发在新的作用域里解析定时器并调用 `__invoke()`，同一个定时器上一次还没结束时跳过这一拍。需要跨触发保留状态的定时器登记成单例，例如 [Heartbeat](../app/Console/Timers/Heartbeat.php) 用 `#[Service('', 'singleton')]`。`#[Timer]` 类放在 `app/Console/Timers/`，与 `app/Console/Commands` 同属 Console 区域；秒间隔 `#[Timer(5)]` 与 Cron `#[Timer(0, '0 * * * *')]` 互斥，Cron 解析复用 `Loom\Cron`（与数据库计划相同五字段）。`make:timer` 可生成任一种骨架。

源码：[ApplicationBuilder](../framework/src/Hosting/ApplicationBuilder.php)、[Application](../framework/src/Hosting/Application.php)、[Host](../framework/src/Hosting/Host.php)、[HttpServer](../framework/src/Http/HttpServer.php)、[TimerRunner](../framework/src/Timer/TimerRunner.php)。测试：`framework/tests/loom_hosting_test.php`、`framework/tests/loom_di_test.php`。
