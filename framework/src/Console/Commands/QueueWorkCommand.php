<?php

declare(strict_types=1);

namespace Loom\Console\Commands;

use Loom\ServiceScope;

/**
 * 消费队列。每个节拍在新作用域里解析 Queue 并 tick 一次。
 */
final class QueueWorkCommand extends \Loom\Console\Command
{
    protected string $signature = 'queue:work {--once : 执行一次后退出}';
    protected string $description = '消费队列';

    public function __construct(
        private \Loom\Doctor $doctor,
        private \Loom\Migrations $migrations,
        private \Loom\Config $config,
        private \Loom\ServiceProvider $services,
        private \Loom\StopSignal $stopSignal,
    ) {
    }

    public function handle(): int
    {
        $this->doctor->check();
        $this->migrations->migrate();
        $once = $this->option('once');
        if (!$once) {
            $this->stopSignal->listen();
        }
        $pulse = new \Loom\RuntimePulse($this->config, 'queue', 1);
        do {
            if (!$once) {
                $pulse->beat();
            }
            $this->services->withMonitoredScope(
                \Loom\Monitoring\MonitoringContext::queue(),
                static function (ServiceScope $scope): void {
                    $scope->get(\Loom\Queue::class)->tick();
                },
            );
            if (!$once) {
                sleep(1);
            }
        } while (!$once);
        return 0;
    }
}
