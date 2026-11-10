# 统一监控

Loom 把一次 **ServiceScope** 内的 HTTP 输入输出、SQL、命令结果与队列事件收进同一份 **Monitoring** 会话。业务依赖 `Loom\Monitoring\Monitoring` 接口，可在应用 `build()` 前换绑实现。

## 登记与替换

框架默认登记 scoped 的 `LoggingMonitoring`。作用域结束时它经根容器单例 `Loom\Logging\Diary`（`JournalWriter` + `JournalFormatter`）写出 `event=monitoring.session` 一行，默认落到标准错误；与 `RequestLog` 的 `http.request` 共用 `config/diary.php`，不要为监控再实现一套写 stderr 的逻辑：

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

业务代码在作用域内注入 `Monitoring` 后可直接 `recordEvent()`；这些事件进入本会话的 `entries`，随 `monitoring.session` 一次性刷出。

### 与日记（Diary）的边界

| 能力 | 接口 | 何时用 |
|------|------|--------|
| 作用域内观测（SQL、HTTP 摘要、队列/定时器标签） | `Monitoring` | 宿主已 `begin()`；在 `dispose()` 前 `record*` |
| 立刻写出的一条结构化日志（含 HTTP 访问行） | `Loom\Logging\Diary` | `RequestLog`、运维脚本、应独立成行的审计 |
| 写出目标与 JSON/文本格式 | `JournalWriter` / `JournalFormatter` | `ApplicationBuilder` 或 `config/diary.php` 配置一次，Monitoring 与 Diary 共享 |

自定义 `Monitoring` 实现若需要落盘，应在 `dispose()` 里调用已注入的 `Diary`，而不是直接 `fwrite` stderr。测试仍可向 `LoggingMonitoring` 传入 callable 覆盖输出，行为与改造前一致。

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

## 关联 id（跨进程）

每次工作单元（HTTP、命令、定时器、队列节拍、`_job`、TCP、gRPC）在打开监控作用域时都会有一个 **correlation id**。框架在下列 JSON 日记字段中写入同一名字：`correlation_id`（`monitoring.session`、`http.request` 等），并在 `MonitoringContext` 的 `tags.correlation_id` 中保留一份。

| 名称 | 含义 |
|------|------|
| HTTP 头 `X-Loom-Correlation-Id` | 入站请求若携带合法 id 则沿用；否则在入口生成 |
| 任务 payload 字段 `_loom_correlation_id` | `Queue::dispatch` 在已监控作用域内自动写入当前 id |
| `_job` 信封字段 `correlation_id` | worker 投递子进程前从 payload 提升，子进程 `JobCommand` 采用 |

应用代码在作用域内注入 `Loom\Monitoring\CorrelationId` 并调用 `current()` 读取当前 id。常量与校验见 `Loom\Monitoring\Correlation`。

出站 **TCP**（`Loom\Native\protocolRequest`）会在当前作用域有 id 时，把 `_loom_correlation_id` 合并进单行 JSON（调用方未手写时）。出站 **gRPC** 同样合并进请求 JSON（含 `StringValue` 双层编码情形），并以 metadata `x-loom-correlation-id` 发出。入站 TCP / gRPC 优先读 Call 上的入站 metadata（Origami `Net\Grpc\Call::incomingMetadata`），否则从请求 JSON 的 `_loom_correlation_id`（或 `correlation_id`）沿用。

grep 示例：

```text
grep '"correlation_id":"7f3a..."' storage/logs/diary.log
```

## 队列说明

Worker 节拍在父进程内监控；任务处理器在 **独立子进程**（`loom.php _job`）中再开一次监控会话。父进程只观测认领/结算，子进程观测实际 Job 处理与 SQL。HTTP 入队的工作应把父请求的 `correlation_id` 经 payload 传到 `_job`，便于在同一份日志里串起整条链路。
