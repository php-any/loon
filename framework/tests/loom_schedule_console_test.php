<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

function scheduleConsoleCheck(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
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
    $out = '';
    $err = '';
    $console = new \Loom\Console\Console(new \Loom\Console\Output(function ($text, $stderr) use (&$out, &$err): void {
        if ($stderr) {
            $err .= $text;
        } else {
            $out .= $text;
        }
    }));
    foreach ([
        \Loom\Console\Commands\ScheduleListCommand::class,
        \Loom\Console\Commands\ScheduleRunCommand::class,
        \Loom\Console\Commands\ScheduleListenCommand::class,
        \Loom\Console\Commands\ScheduleWorkCommand::class,
    ] as $class) {
        $console->add($class);
    }
    $app->services->withScope(static function (\Loom\ServiceScope $scope) use ($console, &$out): void {
        $out = '';
        $code = $console->run(['schedule:list', '--json'], $scope);
        scheduleConsoleCheck($code === 0, 'schedule:list --json exit code');
    });
    $decoded = json_decode(trim($out), true, 64, JSON_THROW_ON_ERROR);
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
    $dryRun = $app->services->withScope(static function (\Loom\ServiceScope $scope) use ($console, &$out): array {
        $out = '';
        scheduleConsoleCheck($console->run(['schedule:work', '--dry-run'], $scope) === 0, 'dry-run exit');

        return json_decode(trim($out), true, 64, JSON_THROW_ON_ERROR);
    });
    scheduleConsoleCheck(($dryRun['count'] ?? 0) >= 1, 'dry-run lists due plan');
    $beforeJobs = $app->services->withScope(static fn (\Loom\ServiceScope $scope): int => count($scope->get(\Loom\Database::class)->query("SELECT id FROM loom_jobs WHERE kind='scheduled'")));
    ob_start();
    try {
        scheduleConsoleCheck($app->run(['schedule:work', '--dry-run']) === 0, 'dry-run from Application::run');
    } finally {
        $appDryRun = json_decode(trim(ob_get_clean() ?: ''), true, 64, JSON_THROW_ON_ERROR);
    }
    scheduleConsoleCheck(($appDryRun['count'] ?? 0) >= 1, 'Application dry-run JSON');
    $afterDry = $app->services->withScope(static fn (\Loom\ServiceScope $scope): int => count($scope->get(\Loom\Database::class)->query("SELECT id FROM loom_jobs WHERE kind='scheduled'")));
    scheduleConsoleCheck($beforeJobs === $afterDry, 'dry-run must not enqueue jobs');
    scheduleConsoleCheck($app->run(['schedule:run', 'console.probe', '--plan']) === 0, 'schedule:run plan');
    $afterRun = $app->services->withScope(static fn (\Loom\ServiceScope $scope): int => count($scope->get(\Loom\Database::class)->query("SELECT id FROM loom_jobs WHERE kind='scheduled'")));
    scheduleConsoleCheck($afterRun === $beforeJobs + 1, 'schedule:run plan enqueues one job');
    scheduleConsoleCheck($app->run(['schedule:listen', '--once']) === 0, 'schedule:listen --once');
    $missing = $app->run(['schedule:run', 'DefinitelyMissingTimer_' . bin2hex(random_bytes(4)), '--timer']);
    scheduleConsoleCheck($missing === 2, 'unknown timer returns input error');
    $scopeProbe = 0;
    $app->services->withScope(static function (\Loom\ServiceScope $scope) use (&$scopeProbe): void {
        $scope->get(\App\Timers\Heartbeat::class);
        $scopeProbe++;
    });
    scheduleConsoleCheck($scopeProbe === 1, 'Heartbeat singleton warm-up');
    scheduleConsoleCheck($app->run(['schedule:run', 'Heartbeat']) === 0, 'schedule:run timer by short name');
} finally {
    $app->dispose();
}

echo "loom_schedule_console_test: OK\n";
