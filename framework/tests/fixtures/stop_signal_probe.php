<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap.php';

$marker = $argv[1] ?? '';
$log = $argv[2] ?? '';
$mode = $argv[3] ?? 'none';

$signal = new \Loom\StopSignal();
if ($mode === 'handler') {
    $signal->onStop(static function () use ($log): void {
        file_put_contents($log, 'handler');
    });
}
$signal->listen();

\spawn(static function () use ($marker): void {
    sleep(30);
    if ($marker !== '') {
        file_put_contents($marker, 'done');
    }
});

while (true) {
    sleep(3600);
}
