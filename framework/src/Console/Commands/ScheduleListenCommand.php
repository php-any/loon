<?php

declare(strict_types=1);

namespace Loom\Console\Commands;

/**
 * 在独立进程中运行代码定时器循环，便于不启动 HTTP 服务时手动验证。
 */
final class ScheduleListenCommand extends \Loom\Console\Command
{
    protected string $signature = 'schedule:listen {--once : 每个代码定时器各执行一次后退出}';
    protected string $description = '运行代码定时器循环';

    public function __construct(
        private \Loom\Doctor $doctor,
        private \Loom\Migrations $migrations,
        private \Loom\TimerRunner $timers,
    ) {
    }

    public function handle(): int
    {
        $this->doctor->check();
        $this->migrations->migrate();
        $registered = $this->timers->timers();
        if ($registered === []) {
            $this->line('未登记代码定时器。');

            return 0;
        }
        if ($this->option('once')) {
            foreach (array_keys($registered) as $class) {
                $this->timers->tick($class);
            }
            $this->info('已对各代码定时器执行一次。');

            return 0;
        }
        $this->timers->start();
        $this->info('代码定时器已启动，按 Ctrl+C 结束。');
        while (true) {
            sleep(3600);
        }
    }
}
