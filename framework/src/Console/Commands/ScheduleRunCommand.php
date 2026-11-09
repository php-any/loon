<?php

declare(strict_types=1);

namespace Loom\Console\Commands;

use Loom\Console\InputError;
use Loom\ServiceScope;

/**
 * 立即执行一次：代码定时器或按名称强制投递数据库计划。
 */
final class ScheduleRunCommand extends \Loom\Console\Command
{
    protected string $signature = 'schedule:run {name : 定时器类短名/全名，或计划名称} {--plan : 强制按数据库计划解析} {--timer : 强制按代码定时器解析}';
    protected string $description = '立即执行定时器或投递计划任务';

    public function __construct(
        private \Loom\Doctor $doctor,
        private \Loom\Migrations $migrations,
        private \Loom\TimerRunner $timers,
        private \Loom\ServiceProvider $services,
    ) {
    }

    public function handle(): int
    {
        $this->doctor->check();
        $this->migrations->migrate();
        $name = (string)$this->argument('name');
        $asPlan = (bool)$this->option('plan');
        $asTimer = (bool)$this->option('timer');
        if ($asPlan && $asTimer) {
            throw new InputError('不能同时指定 --plan 与 --timer');
        }
        $timerClass = $asPlan ? null : $this->resolveTimerClass($name);
        if (!$asPlan && $timerClass !== null && !$asTimer) {
            $this->services->withScope(static function (ServiceScope $scope) use ($timerClass): void {
                $scope->get(\Loom\TimerRunner::class)->tick($timerClass);
            });
            $this->info('已执行代码定时器：' . $timerClass);

            return 0;
        }
        if ($asTimer) {
            $timerClass ??= $this->resolveTimerClass($name);
            if ($timerClass === null) {
                throw new InputError('未找到代码定时器：' . $name);
            }
            $this->services->withScope(static function (ServiceScope $scope) use ($timerClass): void {
                $scope->get(\Loom\TimerRunner::class)->tick($timerClass);
            });
            $this->info('已执行代码定时器：' . $timerClass);

            return 0;
        }
        $this->services->withScope(function (ServiceScope $scope) use ($name): void {
            $scope->get(\Loom\Schedule::class)->runNow($name);
        });
        $this->info('已投递计划任务：' . $name);

        return 0;
    }

    private function resolveTimerClass(string $name): ?string
    {
        foreach (array_keys($this->timers->timers()) as $class) {
            if ($class === $name || str_ends_with($class, '\\' . $name)) {
                return $class;
            }
        }

        return null;
    }
}
