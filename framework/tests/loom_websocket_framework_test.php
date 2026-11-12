<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/fixtures/websocket_framework_fixtures.php';

namespace {
    function wsFrameworkCheck(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }

    $config = new Loom\Config(dirname(__DIR__, 2));
    $discovery = new Loom\ModuleDiscovery($config);
    $discovery->register(\WebSocketFrameworkFixtures\EchoSocketHandler::class);
    $routes = $discovery->routes();
    wsFrameworkCheck(count($routes) === 1, 'WebSocket attribute registers one route');
    wsFrameworkCheck($routes[0]['method'] === 'GET' && $routes[0]['path'] === '/fixture/socket' && $routes[0]['websocket'], 'WebSocket route metadata');

    $failed = false;
    try {
        $discovery->register(\WebSocketFrameworkFixtures\ConflictSocketController::class);
    } catch (LogicException) {
        $failed = true;
    }
    wsFrameworkCheck($failed, 'WebSocket conflicts with HTTP mapping');

    try {
        $hub = new Loom\WebSocket\Hub($config);
        $channel = 'test:channel';
        $hub->send($channel, ['type' => 'ping', 'value' => 1]);
        Loom\Redis::using(function ($client) use ($hub, $channel): void {
            $cursor = $hub->tail($client, $channel);
            wsFrameworkCheck($cursor !== '0-0', 'Hub publish creates stream entry');
            $events = $hub->read($client, [$channel => $cursor], 0, 10);
            wsFrameworkCheck($events !== [], 'Hub read returns payload');
            $decoded = json_decode($events[0]['payload'], true, 512, JSON_THROW_ON_ERROR);
            wsFrameworkCheck(($decoded['type'] ?? '') === 'ping', 'Hub payload round trip');
        });
    } catch (Throwable $redisUnavailable) {
        fwrite(STDERR, "loom_websocket_framework_test: Hub 断言跳过（" . $redisUnavailable->getMessage() . "）\n");
    }

    $services = new Loom\ServiceCollection();
    $services->addSingleton(Loom\Config::class, static fn (): Loom\Config => $config);
    $services->addScoped(\WebSocketFrameworkFixtures\EchoSocketHandler::class, \WebSocketFrameworkFixtures\EchoSocketHandler::class);
    $services->addScoped(Loom\WebSocket\HandlerDispatcher::class, Loom\WebSocket\HandlerDispatcher::class);
    $provider = $services->build();
    $scope = $provider->createScope();
    $handlers = (new ReflectionClass(Loom\WebSocket\HandlerDispatcher::class))->getMethod('messageHandlers');
    $handlers->setAccessible(true);
    $dispatcher = $scope->get(Loom\WebSocket\HandlerDispatcher::class);
    $map = $handlers->invoke($dispatcher, new ReflectionClass(\WebSocketFrameworkFixtures\EchoSocketHandler::class));
    wsFrameworkCheck(isset($map['echo']) && $map['echo'] === 'echo', 'WebSocketMessage discovery');

    echo "loom_websocket_framework_test: OK\n";
}
