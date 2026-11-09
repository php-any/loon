<?php

declare(strict_types=1);

namespace Loom\Monitoring;

use Loom\Disposable;

/**
 * 一次工作单元内的可观测性会话，对应 ASP.NET Core 里 scoped 的 ILogger / Activity 组合。
 *
 * 每个 ServiceScope 解析各自的一份实现；宿主在 HTTP、命令、定时器、队列等入口调用 begin() 后，
 * 同一作用域内的 Database、中间件与业务代码通过构造注入记录 SQL 与自定义事件。作用域释放时 Disposable::dispose() 收尾。
 *
 * 应用可在 build() 前把 Monitoring::class 换绑为 NullMonitoring、LoggingMonitoring 或自建 APM 适配器。
 */
interface Monitoring extends Disposable
{
    /**
     * 标记本会话的工作通道与标签。重复调用时后写的标签合并进当前上下文。
     */
    public function begin(MonitoringContext $context): void;

    public function isActive(): bool;

    public function recordSql(string $sql, array $bindings, float $durationMs, ?int $rowCount = null): void;

    /**
     * @param array{method?: string, path?: string, body_bytes?: int, headers?: array<string, list<string>>} $input
     */
    public function recordHttpInput(array $input): void;

    /**
     * @param array{status?: int, body_bytes?: int, duration_ms?: float} $output
     */
    public function recordHttpOutput(array $output): void;

    /**
     * @param array{command?: string, exit_code?: int, duration_ms?: float} $detail
     */
    public function recordConsole(array $detail): void;

    public function recordEvent(string $name, array $data = []): void;
}
