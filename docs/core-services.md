# 依赖注入与作用域

`build/loom.exe` 是 Go 宿主，里面跑一台 Origami 虚拟机。脚本是 PHP 语法，网络、连接池和并发在 Go 里。不要按 php-fpm（一进程一次请求、请求结束即拆掉环境）来理解这里的作用域。

Loom 的依赖注入照 ASP.NET Core 的 `Microsoft.Extensions.DependencyInjection` 设计：启动时往 `ServiceCollection` 登记，`build()` 得到根 `ServiceProvider`，每个工作单元开一个 `ServiceScope`。

```text
ServiceCollection   启动阶段登记（loom.php）
  └─ build() → ServiceProvider（根，持有单例）
       ├─ ServiceScope   一次 HTTP 请求
       ├─ ServiceScope   一条 TCP 消息
       ├─ ServiceScope   一次 gRPC 调用
       ├─ ServiceScope   一条命令
       └─ ServiceScope   一次队列 / 调度 / 定时器节拍
```

作用域只有这两层。没有按协议分的“核心作用域”，也没有 fork 链。

## 登记

```php
$builder = \Loom\Application::createBuilder($root, $envFile);
$builder->services->addSingleton(Clock::class, SystemClock::class);
$builder->services->addScoped(OrderRepository::class);
$builder->services->addTransient(Mailer::class, static fn (\Loom\ServiceResolver $s): Mailer => new Mailer($s->get(\Loom\Config::class)));
$builder->services->addInstance(Version::class, new Version('1.0'));
$builder->services->configure(PaymentOptions::class, 'payment');
$builder->services->addHostedService(Heartbeat::class);
$app = $builder->build();
```

- 实现可以是类名、接收 `ServiceResolver` 的工厂，或省略（按标识本身构造）。
- 同一标识可以登记多次：`get()` 取最后一条，`getAll()` 按登记顺序取全部。应用的登记因此能覆盖框架默认值。
- `tryAdd*` 只在标识还没有登记时生效。框架在 `build()` 时用它补登记模块里带 `Singleton` / `Scoped` / `Component` 的类，所以应用显式登记的同名服务优先。
- `remove($id)` 删除全部登记，测试用它换掉默认实现。
- `build()` 之后登记表只读。

没有登记、但可以实例化的具体类按 scoped 自动构造，对应控制器、命令、中间件这类“用到就建”的类。

## 生命周期

| 生命周期 | 实例 | 释放 |
| --- | --- | --- |
| singleton | 根容器上一份，所有作用域共用 | `ServiceProvider::dispose()` |
| scoped | 每个作用域一份 | 作用域释放时 |
| transient | 每次解析新建 | 创建它的作用域释放时 |

规则：

- scoped 服务不能在根上解析，会抛出 “Cannot resolve scoped service … from the root provider”。
- singleton 构造时不能依赖 scoped 服务（“Singleton … cannot depend on scoped service …”），避免单例捕获某一次请求的对象。
- 循环依赖抛出 “Dependency cycle: …”。
- 实现 `Loom\Disposable` 的实例由创建它的容器按创建的相反顺序 `dispose()`。
- 单例第一次创建时持有 Go 侧的锁（`withSingletonLock`），并发解析只构造一次。

构造参数可以声明 `ServiceProvider`（根容器）、`ServiceResolver`（发起解析的容器）或 `ServiceScope`（当前作用域）。业务代码一般直接声明要用的服务，不需要拿容器。

## 作用域与数据库

`Database` 和 `RequestContext` 登记为 scoped。`Database` 只保存这一次工作里的事务、命名连接和 `connect()` 改过的 DSN；连接池是进程级的，作用域不拥有连接。语句结束连接回池，事务只活在 `transaction()` 那段回调。

`ServiceScope::run($operation)` 和 `ServiceProvider::withScope($operation)` 在回调期间把本作用域的 `Database` 绑定到执行帧（原生 `withModelDatabase`），模型的静态查询（`Task::query()`、`User::find()`）因此使用这一份。绑定只跟随这次调用及其子调用，回调返回或抛错后恢复；不用 Go context 选择作用域，也没有脚本侧的静态表。

```php
$count = $app->services->withScope(static fn (\Loom\ServiceScope $scope): int => Task::query()->count());
```

宿主为每个工作单元调用 `withScope()`：

| 工作单元 | 开作用域的位置 |
| --- | --- |
| HTTP 请求 | `HttpServer::handle()` |
| TCP 消息 | `TcpServer::handle()`，每条消息一次 |
| gRPC 调用 | `GrpcServer::handle()` |
| 命令 | `Application::run()` |
| 队列 / 调度节拍 | `queue:work` / `schedule:work` 每次 tick |
| 定时器节拍 | `TimerRunner::tick()` |
| 测试里的请求 | `Application::handle()` |

## 请求虚拟机

Origami 在请求虚拟机里执行 HTTP 回调，请求内对脚本对象属性的写入只对该请求可见。因此：

- 路由表、全局中间件、命令表和 gRPC 方法表要在开始监听之前登记完（`loom.php` 里）。
- 单例若在某次请求里第一次被解析，这次创建的对象不会留给后面的请求。需要跨请求保留状态的单例，在 `serve` 开始监听前解析，或者把状态放在 Go 侧。

## 处理器参数绑定

闭包路由和闭包命令的参数按 `Loom\Invoker` 绑定，对应 minimal API：

- 类类型参数先匹配宿主给出的现成实参（`Request`，或命令的 `Input`、`Output`），再从当前作用域解析。
- 无类型或标量参数按顺序取剩下的现成实参，没有时用默认值。

```php
$app->mapGet('/tasks', static fn (\Loom\Request $request, TaskRepository $tasks): \Loom\Response => \Loom\Response::json($tasks->all()));
$app->mapCommand('tasks:count', static fn (\Loom\Console\Output $output, TaskRepository $tasks) => $output->line((string) $tasks->count()));
```

一次工作单元内的 HTTP/SQL/队列等观测见 [统一监控](monitoring.md)（scoped 的 `Monitoring` 接口，默认 `LoggingMonitoring`）。

源码：[ServiceCollection](../framework/src/DependencyInjection/ServiceCollection.php)、[ServiceProvider](../framework/src/DependencyInjection/ServiceProvider.php)、[ServiceScope](../framework/src/DependencyInjection/ServiceScope.php)、[Invoker](../framework/src/DependencyInjection/Invoker.php)、[ModelContext](../framework/src/Database/Orm/ModelContext.php)。
