# 统一监控

Loom 把一次 **ServiceScope** 内的 HTTP 输入输出、SQL、命令结果与队列事件收进同一份 **Monitoring** 会话。业务依赖 `Loom\Monitoring\Monitoring` 接口，可在应用 `build()` 前换绑实现。

监控分三层，互不替代：

| 层 | 职责 |
|----|------|
| **Diary**（`config/diary.php`） | 结构化日志管道；`monitoring.session` 在 driver=log 或 database 时经此写出（database 可同时落库）。 |
| **CorrelationId** | 每个工作单元必定建立关联 id（入站沿用、队列/TCP/gRPC 传递）；与采集开关无关。 |
| **Monitoring 采集 + 仪表盘**（`config/monitoring.php`） | Telescope 风格会话与条目；可配置观测器、忽略列表、仪表盘与保留策略。 |

## 配置 `config/monitoring.php`

由 `MonitoringOptions` 经 `Options::bindOptional` 绑定；**文件缺失不报错**，使用类默认值：`enabled=true`、`driver=log`、全部 watcher 开启、`dashboard.enabled=false`。

主要键：

- `enabled` / `driver`：`log`（仅 Diary）、`database`（表 + 可选 Diary 行）、`null`（不采集，关联 id 仍有效）。
- `ignore_paths` / `ignore_commands`：命中则**跳过整段采集**（不 `begin()`），关联 id 照常。
- `watchers.*`：控制会话**内部**记什么（例如关闭 `sql` 仍保留 HTTP 摘要）；`sql.slow` 为毫秒阈值，&gt;0 时只记录慢于阈值的语句。
- `dashboard`：HTTP 查询 UI 路径（默认 `monitoring`）；**必须**在 builder 上登记 `authorize` 否则一律 403。
- `storage.retain_hours`：`monitoring:prune` 清理窗口。

环境变量（亦见 `.env.example`）：

| 变量 | 含义 |
|------|------|
| `LOOM_MONITORING_ENABLED` | 是否采集（默认 `true`） |
| `LOOM_MONITORING_DRIVER` | `log` / `database` / `null`（默认 `log`） |
| `LOOM_MONITORING_DASHBOARD` | 是否注册 SSR 与 JSON API（默认 `false`） |

启用可查询仪表盘需 **`LOOM_MONITORING_DRIVER=database`**、执行 **`migrate:up`**、**`LOOM_MONITORING_DASHBOARD=1`**，并在 `loom.php` 的 `build()` 前登记 `$builder->monitoring->authorize(...)`（示例应用使用 `monitor.view`）。默认关闭仪表盘，避免未鉴权环境暴露会话细节。

布尔项使用 `env_bool()`，支持 `1` / `0`（以及 `true`/`false`）。`bindOptional` 后 `MonitoringOptions::finalize()` 会为仪表盘 URL（含 `/monitoring/api/*`）自动追加 `ignore_paths`。

## ApplicationBuilder API

闭包不能写在配置文件里；在 `build()` 前链式配置：

```php
$builder = Application::createBuilder(__DIR__);
$builder->monitoring
    ->filter(static function (\Loom\Monitoring\MonitoringSession $session): bool {
        return $session->channel !== 'schedule' || ($session->durationMs ?? 0) < 60000;
    })
    ->tag(static function (\Loom\Monitoring\MonitoringSession $session): array {
        return ['env' => 'prod'];
    })
    ->addWatcher(new \App\Monitoring\BillingWatcher())
    ->authorize(static function (\Loom\ServiceResolver $resolver): bool {
        $auth = $resolver->get(\Loom\Auth::class);
        $admin = $resolver->get(\App\Services\Access\Admin::class);
        try {
            $user = $auth->user();
            return \App\Services\Access\Admin::allows($admin->grants($user['id']), 'monitor.view');
        } catch (\Throwable) {
            return false;
        }
    });
$app = $builder->build();
```

- `filter`：在落 Diary/库之前执行；返回 false 丢弃本会话。
- `tag`：合并进会话 tags。
- `addWatcher`：实现 `MonitoringWatcher`（`start`/`stop` 内可 `recordEvent`）。
- `authorize`：仪表盘门禁（Telescope 风格）。
- `replaceMonitoring()` 或在 `services` 上 `addScoped(Monitoring::class, …)`：**完整替换**实现，框架不再套用 watcher/filter。

## 登记与替换

默认 scoped 实现为 `ConfigurableMonitoring`（`build()` 时登记，覆盖 `LoggingMonitoring` 占位）。作用域结束时按 driver 写 Diary 和/或 `loom_monitoring_*` 表。写出失败不得影响业务。

```php
$builder->monitoring->replaceMonitoring(\Loom\Monitoring\NullMonitoring::class);
// 或
$builder->services->addScoped(\Loom\Monitoring\Monitoring::class, \App\Monitoring\ApmMonitoring::class);
```

测试仍可直接 `new LoggingMonitoring($callable)` 或使用 `ConfigurableMonitoring` 的 test writer 参数（内部用法）。

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

`ScopeMonitoring` 流程：打开作用域 → **establish CorrelationId** → 若配置允许采集则 `begin()` → 业务 → `dispose()` 时 filter/tag → 持久化。

## 观测挂钩

- **SQL**：`Database::query` / `execute` → `recordSql`（绑定值仅类型与长度；`watchers.sql.slow` 过滤）。
- **HTTP**：方法与正文字节、状态与耗时（可忽略 method/status 列表）。
- **命令**：命令名与退出码。
- **队列 / 调度 / tcp / grpc**：按事件名前缀与对应 watcher 归类。
- **应用事件**：`app.event` → `watchers.events`（仅事件类名与监听器数量，无负载）。
- **文件系统**：`filesystem.*` → `watchers.filesystem`（磁盘、键或字段名、大小；无文件正文）。
- **缓存**：`cache.*` → `watchers.cache`；`Loom\Cache` 记录 `cache.hit`、`cache.miss`、`cache.write` 等（不含缓存值），详见 [cache.md](cache.md)。

HTTP 正文与敏感绑定**从不**在 UI 中展示原始值。

### 与日记（Diary）的边界

| 能力 | 接口 | 何时用 |
|------|------|--------|
| 作用域内观测 | `Monitoring` | 采集已 `begin()`；`dispose()` 前 `record*` |
| 独立一行日志 | `Diary` | `RequestLog`、审计脚本 |
| 写出目标与格式 | `JournalWriter` / `JournalFormatter` | 仅 `config/diary.php` |

不要为监控再增加第二套 log-file 配置。`config/diary.php` 的默认 `writer` 为 `stack`（stderr 文本 + 文件 JSON）；`config/monitoring.php` 中 `driver=log` 时 `monitoring.session` 只经 **Diary** 写出，与 RequestLog 共用同一管道。

## 仪表盘

`driver=database` 且 `dashboard.enabled=true` 时，在配置路径（如 `/monitoring`）提供：

- **SSR 页面**：概览（各类型条目计数 + 最近会话分页）、HTTP/SQL/Console/Queue/Schedule/TCP/gRPC/Events/Filesystem/Cache 列表（筛选 + 分页）、会话时间线、按 `correlation_id` 串联会话。
- **JSON API**（同一路径前缀，供管理端观测台调用；须 `authorize`，`driver≠database` 时 **503**）：
  - `GET /monitoring/api/overview` — 最近会话、`counts` 各类型数量、分页元数据
  - `GET /monitoring/api/entries?type=http|console|sql|…` — 按类型列表（`per_page` ≤ 100）
  - `GET /monitoring/api/sessions/{id}` — 会话时间线
  - `GET /monitoring/api/correlation/{id}` — 关联会话链

筛选参数：`tag`、`correlation_id`、`path`、`status`（与 SSR 一致）。须登记 `authorize`（否则 **403**）。仅开仪表盘而 `driver` 不是 `database` 时返回 **503** 说明页或 JSON，而非 403。

迁移：`framework/resources/migrations/0006_monitoring.*`（`migrate:up`）。

清理：`php loom.php monitoring:prune`  honor `storage.retain_hours`。

## 关联 id（跨进程）

每次工作单元在 `ScopeMonitoring` 中建立 **correlation id**。日记字段 `correlation_id` 与 `tags.correlation_id` 一致。

| 名称 | 含义 |
|------|------|
| HTTP 头 `X-Loom-Correlation-Id` | 合法入站 id 则沿用 |
| `_loom_correlation_id` | 队列 payload、出站 TCP/gRPC JSON |
| `_job` 信封 `correlation_id` | 子进程 `JobCommand` adopt |

作用域内注入 `CorrelationId::current()`。常量见 `Loom\Monitoring\Correlation`。

## 手动包一层作用域

```php
$app->services->withMonitoredScope(
    \Loom\Monitoring\MonitoringContext::timer(\App\Timers\Heartbeat::class),
    static function (\Loom\ServiceScope $scope): void {
        $scope->get(\App\Timers\Heartbeat::class)();
    },
);
```

## 队列说明

Worker 节拍在父进程监控；`_job` 子进程单独会话。HTTP 入队应把父请求 correlation 写入 payload，便于日志与仪表盘串联。
