<?php

declare(strict_types=1);

namespace Loom\Monitoring;

/**
 * 监控采集与仪表盘选项，对应 config/monitoring.php。节缺失时保留类默认值。
 */
final class MonitoringOptions
{
    public bool $enabled = true;

    /** log：仅 Diary；database：落库（可同时写 Diary）；null：不采集。 */
    public ?string $driver = 'log';

    /** @var list<string> */
    public array $ignore_paths = [];

    /** @var list<string> */
    public array $ignore_commands = [];

    /**
     * @var array<string, array<string, mixed>>
     */
    public array $watchers = [
        'sql' => ['enabled' => true, 'slow' => 0],
        'http' => ['enabled' => true, 'ignore_methods' => [], 'ignore_status' => []],
        'console' => ['enabled' => true],
        'queue' => ['enabled' => true],
        'schedule' => ['enabled' => true],
        'events' => ['enabled' => true],
        'tcp' => ['enabled' => true],
        'grpc' => ['enabled' => true],
    ];

    public MonitoringDashboardOptions $dashboard;

    public MonitoringStorageOptions $storage;

    public function __construct()
    {
        $this->dashboard = new MonitoringDashboardOptions();
        $this->storage = new MonitoringStorageOptions();
    }

    public function collects(): bool
    {
        if (!$this->enabled) {
            return false;
        }
        $driver = $this->driver;
        return $driver !== null && $driver !== '' && $driver !== 'null';
    }

    public function writesDiary(): bool
    {
        return $this->collects() && ($this->driver === 'log' || $this->driver === 'database');
    }

    public function writesDatabase(): bool
    {
        return $this->collects() && $this->driver === 'database';
    }

    /** @return array<string, mixed> */
    public function watcher(string $name): array
    {
        $w = $this->watchers[$name] ?? [];
        return is_array($w) ? $w : [];
    }

    public function watcherEnabled(string $name): bool
    {
        $w = $this->watcher($name);
        return ($w['enabled'] ?? true) !== false;
    }
}
