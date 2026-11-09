<?php

declare(strict_types=1);

namespace Loom\Console\Commands;

use Loom\ServiceScope;

/**
 * 生成到期任务。每个节拍在新作用域里解析 Schedule 并 tick 一次。
 */
final class ScheduleWorkCommand extends \Loom\Console\Command
{
    protected string $signature = 'schedule:work {--once : 执行一次后退出} {--dry-run : 仅列出到期计划，不投递任务}';
    protected string $description = '生成到期任务';

    public function __construct(
        private \Loom\Doctor $doctor,
        private \Loom\Migrations $migrations,
        private \Loom\Config $config,
        private \Loom\ServiceProvider $services,
    ) {
    }

    public function handle(): int
    {
        $this->doctor->check();
        $this->migrations->migrate();
        if ($this->option('dry-run')) {
            $due = $this->services->withScope(static function (\Loom\ServiceScope $scope): array {
                return $scope->get(\Loom\Schedule::class)->dueSnapshot();
            });
            $this->json(['due' => $due, 'count' => count($due)]);

            return 0;
        }
        $once = $this->option('once');
        $pulse = new \Loom\RuntimePulse($this->config, 'scheduler', 1);
        do {
            if (!$once) {
                $pulse->beat();
            }
            $this->services->withScope(static function (ServiceScope $scope): void {
                $scope->get(\Loom\Schedule::class)->tick();
            });
            if (!$once) {
                sleep(1);
            }
        } while (!$once);
        return 0;
    }
}
