<?php

declare(strict_types=1);

namespace Loom\Monitoring;

/**
 * build() 前由 ApplicationBuilder::monitoring 登记的过滤器、标签器、自定义观测器与仪表盘鉴权。
 */
final class MonitoringRegistration
{
    /** @var ?callable(MonitoringSession): bool */
    private mixed $filter = null;

    /** @var ?callable(MonitoringSession): array<string, scalar|null> */
    private mixed $tagger = null;

    /** @var ?callable(\Loom\ServiceResolver): bool|callable(): bool */
    private mixed $authorize = null;

    /** @var list<MonitoringWatcher> */
    private array $watchers = [];

    private bool $monitoringReplaced = false;

    /** @param callable(MonitoringSession): bool $filter */
    public function setFilter(callable $filter): void
    {
        $this->filter = $filter(...);
    }

    /** @param callable(MonitoringSession): array<string, scalar|null> $tagger */
    public function setTagger(callable $tagger): void
    {
        $this->tagger = $tagger(...);
    }

    /** @param callable(\Loom\ServiceResolver): bool|callable(): bool $authorize */
    public function setAuthorize(callable $authorize): void
    {
        $this->authorize = $authorize(...);
    }

    public function addWatcher(MonitoringWatcher $watcher): void
    {
        $this->watchers[] = $watcher;
    }

    public function markMonitoringReplaced(): void
    {
        $this->monitoringReplaced = true;
    }

    public function monitoringReplaced(): bool
    {
        return $this->monitoringReplaced;
    }

    public function keepSession(MonitoringSession $session): bool
    {
        if ($this->filter === null) {
            return true;
        }
        try {
            return (bool)($this->filter)($session);
        } catch (\Throwable) {
            return true;
        }
    }

    /** @return array<string, scalar|null> */
    public function extraTags(MonitoringSession $session): array
    {
        if ($this->tagger === null) {
            return [];
        }
        try {
            $tags = ($this->tagger)($session);
            return is_array($tags) ? $tags : [];
        } catch (\Throwable) {
            return [];
        }
    }

    public function dashboardAuthorized(?\Loom\ServiceResolver $resolver = null): bool
    {
        if ($this->authorize === null) {
            return false;
        }
        try {
            $callback = $this->authorize;
            $required = (new \ReflectionFunction($callback))->getNumberOfRequiredParameters();
            if ($required > 0) {
                if ($resolver === null) {
                    return false;
                }
                return (bool)$callback($resolver);
            }
            return (bool)$callback();
        } catch (\Throwable) {
            return false;
        }
    }

    /** @return list<MonitoringWatcher> */
    public function watchers(MonitoringOptions $options): array
    {
        $out = [];
        foreach ($this->watchers as $watcher) {
            if ($watcher->enabled($options)) {
                $out[] = $watcher;
            }
        }
        return $out;
    }
}
