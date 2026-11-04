# 宿主与选项

本文说明 Loom 从 .NET 通用宿主接过来的两块设计：把配置节绑定成类型化对象的 Options，以及按登记顺序启动、按相反顺序停止的 Host。请求作用域、中间件和数据库连接的边界仍以 [框架原理](framework-principles.md) 与 [框架能力](framework.md) 为准。

脚本侧对应的是 `Microsoft.Extensions.Options` 里的 `IOptions<T>`，以及 `Microsoft.Extensions.Hosting` 里的 `IHostedService`。Loom 用同步的 `start()` / `stop()`。配置在应用初始化时加载一次，选项对象在第一次解析时定格，没有按请求重绑，也没有配置热更新。

`ServiceScope` 仍然不拥有数据库连接。Host 只管理后台服务的启动和停止，不持有连接，也不代替队列或调度进程。

## 根作用域中的两个单例

应用根作用域创建时注册 `Loom\Options` 和 `Loom\Host`。`fork()` 共享这两个对象，请求、命令和定时器作用域看到的是同一对实例。

`Host` 由工厂直接拿到根容器。容器对象本身按当前作用域解析，singleton 不能把它当成构造依赖，所以不要改成 `Host(Container $container)` 的自动装配。

源码：

- [ServiceScope](../framework/src/Application/ServiceScope.php)
- [Options](../framework/src/Hosting/Options.php)
- [Host](../framework/src/Hosting/Host.php)
- [HostedService](../framework/src/Hosting/HostedService.php)

## Options

选项类是普通的具体类。公开实例属性与配置节里的键同名。构造函数不能有必填参数；必填数据写在属性上，由绑定填入。

```php
namespace App\Options;

final class PaymentOptions
{
    public int $timeout;
    public bool $enabled = false;
    public string $name = '';
    public ?string $note = null;
}
```

```php
// config/payment.php
return [
    'timeout' => 30,
    'enabled' => false,
    'name' => '',
    'note' => null,
];
```

在 `config/services.php` 里登记。容器把它注册为 singleton，第一次 `get()` 时绑定并执行校验，之后各作用域复用同一对象。校验抛错时不缓存，下次解析会重试。

```php
$services->options(\App\Options\PaymentOptions::class, 'payment', static function (\App\Options\PaymentOptions $options): void {
    if ($options->timeout < 1) {
        throw new \LogicException('payment.timeout must be positive');
    }
});
```

业务代码注入 `PaymentOptions`，不要为了读配置再注入容器。

`Options::bind($class, $section)` 每次按当前配置新建对象。`options()` 注册的对象在第一次成功解析后保持那次结果；之后即使 `Config::setRoot()` 清了配置缓存，这个单例也不改写。需要当场读配置时调用 `bind()`。

绑定规则：

- 配置节必须是数组。点分路径可以，例如 `payment.gateway`，读到的值仍须是数组。
- 没有默认值的公开类型化属性必须出现在配置里。已有默认值的属性可以省略，省略时保留默认值。
- `null`、`false`、`0` 和空字符串原样写入。
- 多余的键忽略。
- 类型按声明检查。整数可以写入 `float`。数字字符串不能代替整数、浮点数或布尔值。
- 属性类型是具体类、配置值是数组时，按同一规则递归绑定。
- 私有和受保护属性不参与绑定。
- 异常只包含配置路径和类名，不包含配置值。

单个标量仍可用 `InjectConfig`。一整节要一起校验、一起注入时用 Options。

HTTP 监听使用 [HttpServerOptions](../framework/src/Hosting/HttpServerOptions.php)，默认绑定 `config/http.php` 的 `address` 和 `entry`。`serve` 在执行 `config/services.php` 之后才做这个默认绑定。应用若已注册 `HttpServerOptions`，框架沿用该注册。两个属性都必须是非空字符串。每次请求加载的入口与这个选项对象的 `entry` 相同。

## Host

后台服务实现 `Loom\HostedService`：

```php
namespace App\Workers;

use Loom\HostedService;

final class HeartbeatHost implements HostedService
{
    public function start(): void
    {
    }

    public function stop(): void
    {
    }
}
```

`start()` 开始后续工作。`stop()` 停止安排新的工作，不要求打断已经在执行的那一次。实现应允许停止之后再次启动。

登记放在 `config/services.php`。这个回调在 `serve` 监听前执行，早于 `Host::start()`。

```php
$services->singleton(\App\Workers\HeartbeatHost::class);
$services->get(\Loom\Host::class)->service(\App\Workers\HeartbeatHost::class);
```

`service()` 接受实例或服务标识。类名在 `start()` 时解析。尚未注册的具体类会在此时登记为 singleton。以下情况会拒绝：

- 标识已经是 scoped 或 transient。
- 标识是接口或抽象类，且没有事先注册实现。
- singleton 在构造时依赖 scoped 服务，包括自动装配出来的具体类、`Container` 和 `ServiceScope`。

带 `#[Service]` 的类默认是 scoped。这种类要先 `singleton()`，再 `service()`。`services.php` 早于注解注册执行，显式 singleton 会占住标识，注解不会把它改回 scoped。

生命周期：

- `start()` 按登记顺序调用。重复调用直接返回。
- 某个 `start()` 失败时，已启动的服务按相反顺序 `stop()`，然后抛出原来的异常。回滚时的 `stop()` 错误不覆盖这次启动失败。
- 宿主尚未启动时，`stop()` 直接返回。
- `stop()` 按相反顺序调用。其中一个失败时，其余服务仍会停止，最后抛出第一个停止异常。
- 启动之后不能再 `service()`。停止之后可以再次 `start()`，仍使用原来的登记列表。
- `run($work)` 先 `start()`，再执行回调，最后 `stop()`。回调抛错时仍会停止。

后台服务是进程级 singleton，不要在里面保存某一次请求的状态。需要数据库时，在 `start()` 或每次执行里使用调用当时的作用域，用完关闭。Host 自己不调用 `ServiceScope::close()`。

## serve 如何启动

`Server::run()` 的顺序是：

```text
迁移使用独立作用域，结束后关闭
  → ModuleRegistry::services() 执行 config/services.php
  → 未注册时绑定 HttpServerOptions
  → 校验 address、entry 和网络限制
  → 安装每请求 fork 的 HTTP 回调
  → 再借一个作用域加载入口，确认返回 Application，然后关闭
  → 为每个定时器准备独立作用域，装进 TimerHostedService
  → Host::service(TimerHostedService) 后 Host::run()
  → 已登记的后台服务 start()，定时器在最后启动
  → 阻塞监听
  → 监听返回后按相反顺序 stop()，定时器先停
```

因此应用在 `services.php` 里登记的服务先于定时器启动，停止时定时器先停。队列 `queue:work` 和调度 `schedule:work` 不调用 `Host::run()`，那里登记的后台服务不会启动。

定时器的周期、跳过和互斥仍由 [TimerRunner](../framework/src/Timer/TimerRunner.php) 负责。[TimerHostedService](../framework/src/Hosting/TimerHostedService.php) 只把 `start()` / `stop()` 接进 Host。每个定时器继续使用自己的作用域，不和请求或其他定时器共享事务。

## 和请求的边界

HTTP 请求、TCP、gRPC 和队列作业各自 `fork()`。它们共享 `Options` 和 `Host` 的实例，不共享 scoped 服务。请求路径只读取已经绑定的选项，不启动 Host。

修改选项类、后台服务或 `config/services.php` 后，重启对应的长期进程。`framework/tests/loom_hosting_test.php` 覆盖绑定、快照、启动顺序、失败回滚和 singleton 约束。
