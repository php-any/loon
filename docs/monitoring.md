# 统一监控

Loom 把监控分成三层，不要混用：

| 层 | 作用 | 配置 |
|----|------|------|
| **Diary** | 结构化日志管道（`http.request`、业务 `Diary::event` 等） | `config/diary.php` → `DiaryOptions` |
| **CorrelationId** | 每个工作单元的关联 id（入站沿用、出站 `Queue::dispatch` / 协议 stamping） | 无开关；始终 establish |
| **Monitoring 会话** | 作用域内缓冲 SQL/HTTP/console/events，dispose 写出 `monitoring.session` | `config/monitoring.php` → `MonitoringOptions` |

业务依赖 `Loom\Monitoring\Monitoring` 接口采集会话数据；关联 id 用 `Loom\Monitoring\CorrelationId::current()`。**Monitoring 输出必须经 Diary**，不要在 `config/monitoring.php` 里再配一套日志文件。

## 配置（`config/monitoring.php`）

`ApplicationBuilder` 用 `Options::bindOptional` 绑定 `MonitoringOptions`：文件缺失时与仓库样板一致（`driver=log`、全部通道与 record 开关为 `true`），应用不会因缺文件而启动失败。

| 键 | 含义 |
|----|------|
| `driver` | `log`（默认）：scoped `LoggingMonitoring`；`null`：默认解析为 `NullMonitoring`，且不对任何通道 `begin()` |
| `channels.*` | 按宿主入口（`http`、`console`、`timer`、`queue`、`schedule`、`job`、`tcp`、`grpc`）过滤是否开启会话；`false` 时跳过 `begin()` 与缓冲，**CorrelationId 仍会 establish** |
| `record.*` | 在已 active 的会话内控制 `recordSql` / `recordHttp*` / `recordConsole` / `recordEvent` |

环境变量 `LOOM_MONITORING_DRIVER` 可覆盖 `driver`。日记写出目标与 JSON/文本格式仍只由 `config/diary.php` 决定。

## 登记与替换

框架默认登记 scoped 的 `LoggingMonitoring`（`driver=log` 时）。作用域结束时它经根容器单例 `Loom\Logging\Diary` 写出 `event=monitoring.session`；与 `RequestLog` 共用 `JournalWriter` / `JournalFormatter`。

`tryAddScoped(Monitoring::class, …)` 仍可在 `build()` 前覆盖：**自定义绑定会取代默认工厂**（含 `driver` 选择）。自定义 APM 适配器可实现 `Monitoring` 与 `Disposable`，并在需要时注入 `MonitoringOptions` 以复用 `channels` / `record` 过滤；在 `dispose()` 里把缓冲数据刷到外部系统或经 `Diary` 写出。

关闭收集的两种方式：

```php
// 配置：config/monitoring.php 中 driver => 'null' 或关闭单个 channels.*
```

```php
// 代码：build() 前换绑实现（与配置无关，优先级高于默认工厂）
$builder = Application::createBuilder(__DIR__);
$builder->services->addScoped(
    \Loom\Monitoring\Monitoring::class,
    \Loom\Monitoring\NullMonitoring::class,
);
$app = $builder->build();
```

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
