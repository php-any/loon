<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

function scheduleConsoleCheck(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/**
 * @return array{0: int, 1: string} 退出码与标准输出（应用 Console 默认走 echo）
 */
function scheduleConsoleRun(\Loom\Application $app, array $args): array
{
    ob_start();
    try {
        $code = $app->run($args);
    } finally {
        $stdout = ob_get_clean();
        if ($stdout === false) {
            $stdout = '';
        }
    }

    return [$code, $stdout];
}

$app = (static function () { return require dirname(__DIR__, 2) . '/loom.php'; })();
try {
    $registry = $app->services->get(\Loom\Console\Console::class);
    foreach (['schedule:list', 'schedule:run', 'schedule:listen', 'schedule:work'] as $name) {
        scheduleConsoleCheck($registry->has($name), 'Registry has ' . $name);
        ob_start();
        try {
            scheduleConsoleCheck($app->run(['help', $name]) === 0, 'Help for ' . $name);
            scheduleConsoleCheck($app->run([$name, '--help']) === 0, 'Help option for ' . $name);
        } finally {
            ob_end_clean();
        }
    }
    [$listCode, $listStdout] = scheduleConsoleRun($app, ['schedule:list', '--json']);
    scheduleConsoleCheck(
        $listCode === 0,
        'schedule:list --json exit code=' . $listCode . ' stdout=' . substr(trim($listStdout), 0, 500),
    );
    $decoded = json_decode(trim($listStdout), true, 64, JSON_THROW_ON_ERROR);
    scheduleConsoleCheck(isset($decoded['timers'], $decoded['plans'], $decoded['now']), 'schedule:list JSON shape');
    $hasHeartbeat = false;
    foreach ($decoded['timers'] as $row) {
        if (($row['class'] ?? '') === \App\Timers\Heartbeat::class) {
            $hasHeartbeat = true;
        }
    }
    scheduleConsoleCheck($hasHeartbeat, 'schedule:list includes Heartbeat timer');
    $app->services->withScope(static function (\Loom\ServiceScope $scope): void {
        $scope->get(\Loom\Schedule::class)->every('console.probe', 60, 'scheduled', [], 1);
        $scope->get(\Loom\Database::class)->execute('UPDATE loom_schedules SET next_run=1 WHERE name=?', ['console.probe']);
    });
    [$dryCode, $dryStdout] = scheduleConsoleRun($app, ['schedule:work', '--dry-run']);
    scheduleConsoleCheck(
        $dryCode === 0,
        'schedule:work --dry-run exit code=' . $dryCode . ' stdout=' . substr(trim($dryStdout), 0, 500),
    );
    $dryRun = json_decode(trim($dryStdout), true, 64, JSON_THROW_ON_ERROR);
    scheduleConsoleCheck(($dryRun['count'] ?? 0) >= 1, 'dry-run lists due plan');
    $beforeJobs = $app->services->withScope(static fn (\Loom\ServiceScope $scope): int => count($scope->get(\Loom\Database::class)->query("SELECT id FROM loom_jobs WHERE kind='scheduled'")));
    [$dryAgainCode, $dryAgainStdout] = scheduleConsoleRun($app, ['schedule:work', '--dry-run']);
    scheduleConsoleCheck($dryAgainCode === 0, 'repeat dry-run exit code=' . $dryAgainCode);
    scheduleConsoleCheck(($dryRun['count'] ?? 0) === (json_decode(trim($dryAgainStdout), true, 64, JSON_THROW_ON_ERROR)['count'] ?? -1), 'dry-run stable count');
    $afterDry = $app->services->withScope(static fn (\Loom\ServiceScope $scope): int => count($scope->get(\Loom\Database::class)->query("SELECT id FROM loom_jobs WHERE kind='scheduled'")));
    scheduleConsoleCheck($beforeJobs === $afterDry, 'dry-run must not enqueue jobs');
    [$runPlanCode] = scheduleConsoleRun($app, ['schedule:run', 'console.probe', '--plan']);
    scheduleConsoleCheck($runPlanCode === 0, 'schedule:run plan exit code=' . $runPlanCode);
    $afterRun = $app->services->withScope(static fn (\Loom\ServiceScope $scope): int => count($scope->get(\Loom\Database::class)->query("SELECT id FROM loom_jobs WHERE kind='scheduled'")));
    scheduleConsoleCheck($afterRun === $beforeJobs + 1, 'schedule:run plan enqueues one job');
    [$listenCode] = scheduleConsoleRun($app, ['schedule:listen', '--once']);
    scheduleConsoleCheck($listenCode === 0, 'schedule:listen --once exit code=' . $listenCode);
    $missing = $app->run(['schedule:run', 'DefinitelyMissingTimer_' . bin2hex(random_bytes(4)), '--timer']);
    scheduleConsoleCheck($missing === 2, 'unknown timer returns input error, got ' . $missing);
    $scopeProbe = 0;
    $app->services->withScope(static function (\Loom\ServiceScope $scope) use (&$scopeProbe): void {
        $scope->get(\App\Timers\Heartbeat::class);
        $scopeProbe++;
    });
    scheduleConsoleCheck($scopeProbe === 1, 'Heartbeat singleton warm-up');
    [$runTimerCode] = scheduleConsoleRun($app, ['schedule:run', 'Heartbeat']);
    scheduleConsoleCheck($runTimerCode === 0, 'schedule:run timer exit code=' . $runTimerCode);
} finally {
    $app->dispose();
}

echo "loom_schedule_console_test: OK\n";
