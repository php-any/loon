<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

/**
 * 断言测试条件成立，失败时立即抛出异常以终止当前测试。
 */
function checkTimer(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$root = dirname(__DIR__, 2);
$config = new \Loom\Config($root);
$config->load();
$provider = (new \Loom\ServiceCollection())
    ->addInstance(\Loom\Config::class, $config)
    ->build();

require $root . '/tests/fixtures/timers/Declarations.php';

$runner = new \Loom\TimerRunner($provider);
$runner->register(NotATimer::class);
$runner->discover($config->path('app/Console/Timers'));
foreach ([InvalidTimerInterval::class, InvalidTimerHandler::class, DuplicateTimer::class, InvalidTimerBoth::class, InvalidTimerCron::class] as $class) {
    $rejected = false;
    try {
        $runner->register($class);
    } catch (Throwable $error) {
        $rejected = true;
    }
    checkTimer($rejected, 'Invalid timer must be rejected: ' . $class);
}
$runner->register(TimerProbe::class);
$runner->register(TimerProbe::class);
$probe = $provider->get(TimerProbe::class);
$runner->start();
$runner->start();
checkTimer($probe->calls === 0, 'First execution must wait for interval');
usleep(1200000);
checkTimer($probe->calls === 1, 'Duplicate registration/start must not duplicate execution');
$runner->stop();
usleep(1100000);
checkTimer($probe->calls === 1, 'Stopped timer must not execute');
$runner->start();
usleep(1200000);
checkTimer($probe->calls === 2, 'Restart must create only one execution loop');
$runner->stop();
// 同时运行短任务和超周期处理器，验证相位与相互独立性。
$timed = new \Loom\TimerRunner($provider);
$timed->register(SlowTimerProbe::class);
$timed->register(ShortTimerProbe::class);
$slow = $provider->get(SlowTimerProbe::class);
$short = $provider->get(ShortTimerProbe::class);
$startedAt = microtime(true);
$timed->start();
usleep(4700000);
$timed->stop();
checkTimer(count($slow->starts) === 2, 'Over-period handler must skip ticks without catch-up');
checkTimer($slow->maxActive === 1, 'Same timer must never overlap');
checkTimer(count($short->starts) === 4, 'Short task must keep fixed cadence independently of slow task');
foreach ($slow->starts as $index => $at) {
    checkTimer(abs($at - $startedAt - (1 + 2 * $index)) < 0.25, 'Slow task must resume on original tick grid');
}
foreach ($short->starts as $index => $at) {
    checkTimer(abs($at - $startedAt - (1 + $index)) < 0.25, 'Handler duration must not shift the period');
}
$cronOnly = new \Loom\TimerRunner($provider);
checkTimer($cronOnly->register(CronTimerProbe::class), 'Cron timer registers');
checkTimer(($cronOnly->timers()[CronTimerProbe::class] ?? -1) === 0, 'Cron timer maps to zero seconds sentinel');
$cronFive = new \Loom\Cron('*/5 * * * *', 'UTC');
$beforeDue = strtotime('2026-06-01 12:03:00 UTC');
$nextDue = $cronFive->next($beforeDue);
checkTimer($nextDue === strtotime('2026-06-01 12:05:00 UTC'), 'Cron next matches five-minute grid');
$notYet = strtotime('2026-06-01 12:04:30 UTC');
checkTimer($cronFive->next($notYet) === strtotime('2026-06-01 12:05:00 UTC'), 'Cron next skips before due minute');
echo "loom_timer_test: OK\n";
