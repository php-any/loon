<?php

declare(strict_types=1);

namespace Loom\Console\Commands;

use Loom\Models\ScheduledJob;
use Loom\ServiceScope;

/**
 * 列出代码声明的定时器与数据库中的计划任务。
 */
final class ScheduleListCommand extends \Loom\Console\Command
{
    protected string $signature = 'schedule:list {--json : 以 JSON 输出}';
    protected string $description = '列出代码定时器与数据库计划';

    public function __construct(
        private \Loom\Doctor $doctor,
        private \Loom\Migrations $migrations,
        private \Loom\ModuleDiscovery $modules,
        private \Loom\ServiceProvider $services,
    ) {
    }

    public function handle(): int
    {
        $this->doctor->check();
        $this->migrations->migrate();
        $now = time();
        $payload = $this->services->withScope(function (ServiceScope $scope) use ($now): array {
            $timers = $this->modules->timerDefinitions();
            $plans = [];
            foreach (ScheduledJob::using($scope->get(\Loom\Database::class))->orderBy('name')->rows(['name', 'kind', 'enabled', 'interval_seconds', 'next_run', 'expression', 'timezone']) as $row) {
                $plans[] = [
                    'name' => $row['name'],
                    'kind' => $row['kind'],
                    'enabled' => (bool)(int)$row['enabled'],
                    'interval_seconds' => (int)$row['interval_seconds'],
                    'next_run' => (int)$row['next_run'],
                    'expression' => $row['expression'],
                    'timezone' => $row['timezone'],
                    'due' => (int)$row['enabled'] === 1 && (int)$row['next_run'] <= $now,
                ];
            }

            return ['timers' => $timers, 'plans' => $plans, 'now' => $now];
        });
        if ($this->option('json')) {
            $this->json($payload);

            return 0;
        }
        if ($payload['timers'] !== []) {
            $this->info('代码定时器（#[Timer]）');
            $this->table(['类', '调度', '说明'], array_map(
                static fn (array $row): array => [$row['class'], $row['schedule'] ?? (string)$row['seconds'], $row['state']],
                $payload['timers'],
            ));
        }
        if ($payload['plans'] !== []) {
            if ($payload['timers'] !== []) {
                $this->line('');
            }
            $this->info('数据库计划（loom_schedules）');
            $this->table(
                ['名称', '任务种类', '启用', '到期', '下次运行'],
                array_map(
                    static function (array $row) use ($now): array {
                        return [
                            $row['name'],
                            $row['kind'],
                            $row['enabled'] ? '是' : '否',
                            $row['due'] ? '是' : '否',
                            date('Y-m-d H:i:s', $row['next_run']),
                        ];
                    },
                    $payload['plans'],
                ),
            );
        }
        if ($payload['timers'] === [] && $payload['plans'] === []) {
            $this->line('未登记代码定时器，也未找到数据库计划。');
        }

        return 0;
    }
}
