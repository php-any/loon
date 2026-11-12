<?php

require dirname(__DIR__, 3) . '/framework/bootstrap.php';
require_once __DIR__ . '/IsolationProbeController.php';
require_once __DIR__ . '/IsolationRecord.php';

$app = \Loom\Application::createBuilder(__DIR__)->build();
$app->mapGet('/api/isolation', [\Fixtures\Http\IsolationProbeController::class, 'probe']);
$app->mapWebSocket('/ws/echo', static function (\Loom\Request $request, \Net\Websocket\Conn $connection): void {
    $connection->writeText($connection->readText());
});
try {
    exit($app->run(['serve']));
} finally {
    $app->dispose();
}
