<?php

declare(strict_types=1);

namespace Loom;

use Loom\Monitoring\MonitoringRegistration;
use Loom\Monitoring\MonitoringSession;
use Loom\Monitoring\MonitoringWatcher;

/**
 * ApplicationBuilder::monitoring 上的链式配置入口。
 */
final class MonitoringBuilder
{
    public function __construct(
        private readonly ServiceCollection $services,
        private readonly MonitoringRegistration $registration,
    ) {
    }

    /** @param callable(MonitoringSession): bool $filter */
    public function filter(callable $filter): self
    {
        $this->registration->setFilter($filter);
        return $this;
    }

    /** @param callable(MonitoringSession): array<string, scalar|null> $tagger */
    public function tag(callable $tagger): self
    {
        $this->registration->setTagger($tagger);
        return $this;
    }

    public function addWatcher(MonitoringWatcher $watcher): self
    {
        $this->registration->addWatcher($watcher);
        return $this;
    }

    /** @param callable(\Loom\ServiceResolver): bool|callable(): bool $authorize */
    public function authorize(callable $authorize): self
    {
        $this->registration->setAuthorize($authorize);
        return $this;
    }

    /**
     * 在 build() 前完整替换 Monitoring 实现；框架不再套用观测器与 filter。
     */
    public function replaceMonitoring(string|callable $implementation): self
    {
        $this->registration->markMonitoringReplaced();
        $this->services->addScoped(\Loom\Monitoring\Monitoring::class, $implementation);
        return $this;
    }

    public function registration(): MonitoringRegistration
    {
        return $this->registration;
    }
}
