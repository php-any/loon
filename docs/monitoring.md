# 统一监控

Loom 把一次 **ServiceScope** 内的 HTTP 输入输出、SQL、命令结果与队列事件收进同一份 **Monitoring** 会话。业务依赖 `Loom\Monitoring\Monitoring` 接口，可在应用 `build()` 前换绑实现。

## 登记与替换

框架默认登记 scoped 的 `LoggingMonitoring`（作用域结束时写一行 JSON 到标准错误）：

```php
$builder = Application::createBuilder(__DIR__);
$builder->services->addScoped(
    \Loom\Monitoring\Monitoring::class,
    \Loom\Monitoring\NullMonitoring::class,
);
$app = $builder->build();
```

自定义 APM 适配器实现 `Monitoring` 与 `Disposable`，在 `dispose()` 里把缓冲 Span/事件刷到外部系统即可。

## 宿主入口

| 入口 | 通道 | 绑定方式 |
|------|------|----------|
| HTTP | `http` | `HttpServer::handle` / `Application::handle` 使用 `withMonitoredScope` |
| CLI | `console` | `Application::run` 与 `Console::dispatch` |
| 定时器 | `timer` | `TimerRunner::tick` |
| 队列 worker | `queue` | `queue:work` 每个节拍的作用域 |
| 调度 worker | `schedule` | `schedule:work` 每个节拍的作用域 |
| 队列子进程 `_job` | `job` | `JobCommand` 在解析信封后 `begin` |
| TCP / gRPC | `tcp` / `grpc` | 各 `*Server::handle` |

## 观测挂钩

- **SQL**：`Database::query` / `execute` 自动调用 `recordSql`（绑定值只记录类型与长度）。
- **HTTP**：`HttpServer::respond` 记录请求方法与正文字节数、响应状态与耗时。
- **命令**：`Console::dispatch` 记录命令名与退出码。
- **队列**：`Queue::tick` 认领任务时 `recordEvent('queue.job.claimed', …)`；子进程内 `queue.job.dispatch`。

业务代码在作用域内注入 `Monitoring` 后可直接 `recordEvent()`。

## 手动包一层作用域

```php
$app->services->withMonitoredScope(
    \Loom\Monitoring\MonitoringContext::timer(\App\Timers\Heartbeat::class),
    static function (\Loom\ServiceScope $scope): void {
        $scope->get(\App\Timers\Heartbeat::class)();
    },
);
```

（`ServiceProvider::withMonitoredScope` 与 `ScopeMonitoring::run` 等价。）

## 队列说明

Worker 节拍在父进程内监控；任务处理器在 **独立子进程**（`loom.php _job`）中再开一次监控会话。父进程只观测认领/结算，子进程观测实际 Job 处理与 SQL。
