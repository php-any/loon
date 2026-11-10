<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

function stopCheck(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function loomTestBinary(string $root): string
{
    foreach (['loom.exe', 'loom'] as $name) {
        $candidate = $root . '/build/' . $name;
        if (is_file($candidate)) {
            return $candidate;
        }
    }

    throw new LogicException('Build the Loom host first (expected build/loom or build/loom.exe under ' . $root . ')');
}

$signal = new Loom\StopSignal();
$invoked = false;
$signal->onStop(static function () use (&$invoked): void {
    $invoked = true;
});
$signal->dispatch(false);
stopCheck($invoked, 'Registered onStop handler runs on dispatch');

$again = new Loom\StopSignal();
$count = 0;
$again->onStop(static function () use (&$count): void {
    $count++;
});
$again->dispatch(false);
$again->dispatch(false);
stopCheck($count === 1, 'Dispatch runs handler only once');

$repoRoot = dirname(__DIR__, 2);
$probe = dirname(__DIR__) . '/tests/fixtures/stop_signal_probe.php';
$loom = loomTestBinary($repoRoot);

$token = bin2hex(random_bytes(8));
$marker = sys_get_temp_dir() . '/loom-stop-' . $token;
$log = sys_get_temp_dir() . '/loom-stop-log-' . $token;
@unlink($marker);
@unlink($log);

$command = [$loom, $probe, $marker, $log, 'handler'];
$probeStarted = microtime(true);
$process = proc_open(
    $command,
    [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
    $pipes,
    $repoRoot,
);
if (!is_resource($process)) {
    throw new RuntimeException('Cannot start stop probe');
}
usleep(250000);
proc_terminate($process, 15);
proc_close($process);
stopCheck(microtime(true) - $probeStarted < 2.5, 'Stop exits without waiting for in-flight sleep');
stopCheck(is_file($log) && trim((string)file_get_contents($log)) === 'handler', 'Subprocess onStop handler ran on SIGTERM');
stopCheck(!is_file($marker) || trim((string)@file_get_contents($marker)) !== 'done', 'Framework does not wait for in-flight work');

@unlink($marker);
@unlink($log);

$commandBare = [$loom, $probe, $marker, $log, 'none'];
$bareStarted = microtime(true);
$bare = proc_open(
    $commandBare,
    [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
    $pipesBare,
    $repoRoot,
);
usleep(250000);
proc_terminate($bare, 15);
$bareCode = proc_close($bare);
stopCheck(microtime(true) - $bareStarted < 2.5, 'No handler exits promptly');
stopCheck($bareCode === 0 || $bareCode === 143 || $bareCode === -15 || $bareCode === 15, 'No handler still terminates on signal');

echo "loom_shutdown_test: OK\n";
