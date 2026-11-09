<?php

declare(strict_types=1);

namespace Loom\Monitoring;

/**
 * 空实现：begin 与 record 均为无操作，作用域释放时不写出任何内容。
 */
final class NullMonitoring implements Monitoring
{
    public function begin(MonitoringContext $context): void
    {
    }

    public function isActive(): bool
    {
        return false;
    }

    public function recordSql(string $sql, array $bindings, float $durationMs, ?int $rowCount = null): void
    {
    }

    public function recordHttpInput(array $input): void
    {
    }

    public function recordHttpOutput(array $output): void
    {
    }

    public function recordConsole(array $detail): void
    {
    }

    public function recordEvent(string $name, array $data = []): void
    {
    }

    public function dispose(): void
    {
    }
}
