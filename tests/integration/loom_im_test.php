<?php

declare(strict_types=1);

/**
 * IM HTTP/WebSocket 集成测试：需要 loom.exe、MySQL 与 Redis。
 * 未配置运行时时跳过断言并打印说明。
 */

require dirname(__DIR__, 2) . '/loom.php';

namespace {
    $loom = dirname(__DIR__, 2) . '/build/loom.exe';
    if (!is_file($loom)) {
        fwrite(STDERR, "loom_im_test: SKIP（未找到 build/loom.exe）\n");
        exit(0);
    }

    $app = $GLOBALS['app'];
    $db = $app->provider()->createScope()->get(Loom\Database::class);

    try {
        $db->query('SELECT 1 FROM im_conversations LIMIT 1');
    } catch (Throwable $error) {
        fwrite(STDERR, "loom_im_test: SKIP（请先执行 migrate:up 应用 012_im）\n");
        exit(0);
    }

    function imCheck(bool $ok, string $message): void
    {
        if (!$ok) {
            throw new RuntimeException($message);
        }
    }

    $scope = $app->provider()->createScope();
  /** @var App\Services\Im\ImMessaging $im */
    $im = $scope->get(App\Services\Im\ImMessaging::class);

    $users = $db->query('SELECT id FROM loom_users ORDER BY id ASC LIMIT 2');
    imCheck(count($users) >= 2, '需要至少两名用户');
    $a = (int)$users[0]['id'];
    $b = (int)$users[1]['id'];

    $conversation = $im->createConversation($a, 'direct', null, [$b]);
    $conversationId = (int)$conversation['id'];

    $forbidden = false;
    try {
        $im->sendMessage($conversationId, max($a, $b) + 100000, 'hello');
    } catch (Loom\HttpError $error) {
        $forbidden = $error->status === 403;
    }
    imCheck($forbidden, '非成员不能发消息');

    $message = $im->sendMessage($conversationId, $a, 'integration ping');
    imCheck($message['body'] === 'integration ping', '发送后持久化');

    $listed = $im->messages($conversationId, $a, 1);
    imCheck(count($listed['items']) >= 1, '历史列表可读');

    $hub = $scope->get(Loom\WebSocket\Hub::class);
    $channel = App\Services\Im\ImMessaging::conversationChannel($conversationId);
    Loom\Redis::using(static function ($client) use ($hub, $channel): void {
        $cursor = $hub->tail($client, $channel);
        $events = $hub->read($client, [$channel => $cursor], 100, 10);
        imCheck($events !== [], 'Hub 推送会话消息');
    });

    $router = $app->router();
    imCheck($router->match('GET', '/api/im/socket')->websocket, 'IM WebSocket 路由已登记');

    echo "loom_im_test: OK\n";
}
