<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

function stopCheck(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
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

$probe = dirname(__DIR__) . '/tests/fixtures/stop_signal_probe.php';
$loom = dirname(__DIR__, 2) . '/build/loom';
$runner = is_file($loom) ? $loom : PHP_BINARY;
$runTarget = is_file($loom) ? $probe : $probe;

$token = bin2hex(random_bytes(8));
$marker = sys_get_temp_dir() . '/loom-stop-' . $token;
$log = sys_get_temp_dir() . '/loom-stop-log-' . $token;
@unlink($marker);
@unlink($log);

$command = is_file($loom)
    ? [$loom, $runTarget, $marker, $log, 'handler']
    : [PHP_BINARY, $runTarget, $marker, $log, 'handler'];
$probeStarted = microtime(true);
$process = proc_open(
    $command,
    [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
    $pipes,
    dirname(__DIR__, 2),
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

$commandBare = is_file($loom)
    ? [$loom, $runTarget, $marker, $log, 'none']
    : [PHP_BINARY, $runTarget, $marker, $log, 'none'];
$bareStarted = microtime(true);
$bare = proc_open(
    $commandBare,
    [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
    $pipesBare,
    dirname(__DIR__, 2),
);
usleep(250000);
proc_terminate($bare, 15);
$bareCode = proc_close($bare);
stopCheck(microtime(true) - $bareStarted < 2.5, 'No handler exits promptly');
stopCheck($bareCode === 0 || $bareCode === 143 || $bareCode === -15 || $bareCode === 15, 'No handler still terminates on signal');

echo "loom_shutdown_test: OK\n";
