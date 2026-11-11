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
        'filesystem' => ['enabled' => true],
        'tcp' => ['enabled' => true],
        'grpc' => ['enabled' => true],
        'cache' => ['enabled' => true],
    ];

    public MonitoringDashboardOptions $dashboard;

    public MonitoringStorageOptions $storage;

    public function __construct()
    {
        $this->dashboard = new MonitoringDashboardOptions();
        $this->storage = new MonitoringStorageOptions();
    }

    public static function coerceBool(mixed $raw, bool $default): bool
    {
        return \loom_bool_value($raw, $default);
    }

    /**
     * bindOptional 之后调用：规范化 watcher 开关，并在仪表盘开启时自动忽略其 URL 前缀。
     */
    public function finalize(): void
    {
        $this->enabled = self::coerceBool($this->enabled, true);
        $this->dashboard->enabled = self::coerceBool($this->dashboard->enabled, false);
        foreach ($this->watchers as $name => $watcher) {
            if (!is_array($watcher) || !array_key_exists('enabled', $watcher)) {
                continue;
            }
            $this->watchers[$name]['enabled'] = self::coerceBool($watcher['enabled'], true);
        }
        if ($this->dashboard->enabled) {
            $prefix = '/' . trim($this->dashboard->path !== '' ? $this->dashboard->path : 'monitoring', '/');
            $this->ignore_paths = array_values(array_unique(array_merge(
                $this->ignore_paths,
                [$prefix, $prefix . '/*'],
            )));
        }
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
        return self::coerceBool($w['enabled'] ?? true, true);
    }
}
