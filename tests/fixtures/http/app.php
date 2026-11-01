<?php

require_once __DIR__ . '/IsolationProbeController.php';
$app = new \Loom\Application($services);
$app->get('/api/isolation', \Fixtures\Http\IsolationProbeController::class);
$app->websocket('/ws/echo', function (\Loom\Request $request, \Net\Websocket\Conn $connection): void {
    $connection->writeText($connection->readText());
});
return $app;
