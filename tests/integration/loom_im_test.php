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
        $db->query('SELECT role FROM im_conversation_members LIMIT 1');
    } catch (Throwable $error) {
        fwrite(STDERR, "loom_im_test: SKIP（请先执行 migrate:up 应用 012_im 与 013_im_chat）\n");
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
    /** @var App\Services\Im\ImAuth $imAuth */
    $imAuth = $scope->get(App\Services\Im\ImAuth::class);
    /** @var Loom\Auth $studioAuth */
    $studioAuth = $scope->get(Loom\Auth::class);
    $context = $scope->get(Loom\RequestContext::class);

    $users = $db->query('SELECT id, email, password FROM loom_users ORDER BY id ASC LIMIT 3');
    imCheck(count($users) >= 2, '需要至少两名用户');
    $a = (int)$users[0]['id'];
    $b = (int)$users[1]['id'];
  $password = 'im integration password';
    if (!password_verify($password, $users[0]['password'])) {
        $db->execute('UPDATE loom_users SET password=? WHERE id IN (?, ?)', [password_hash($password, PASSWORD_BCRYPT, ['cost' => 4]), $a, $b]);
    }

    $originRequest = static fn (string $method, string $path, string $cookie = '', string $csrf = '', string $body = ''): Loom\Request => new Loom\Request([
        'origin' => 'http://localhost',
        'method' => $method,
        'path' => $path,
        'headers' => [
            'host' => ['localhost'],
            'origin' => ['http://localhost'],
            'cookie' => $cookie === '' ? [] : [$cookie],
            'x-csrf-token' => $csrf === '' ? [] : [$csrf],
            'content-type' => $body === '' ? [] : ['application/json'],
        ],
        'query' => [],
        'body' => $body,
    ]);

    $context->bind($originRequest('POST', '/api/studio/login', '', '', ''));
    $studio = $studioAuth->login($users[0]['email'], $password);
    $studioCookie = explode(';', $studio['cookie'])[0];

    $context->bind($originRequest('POST', '/api/im/login', '', '', json_encode(['email' => $users[0]['email'], 'password' => $password], JSON_THROW_ON_ERROR)));
    $chat = $imAuth->login($users[0]['email'], $password);
    $chatCookie = explode(';', $chat['cookie'])[0];

    imCheck($studioCookie !== $chatCookie, '聊天与后台 cookie 相互隔离');

    $context->bind($originRequest('GET', '/api/admin/session', $studioCookie));
    imCheck($studioAuth->user()['id'] === $a, '后台 cookie 仍可登录管理端');

    $context->bind($originRequest('GET', '/api/im/session', $chatCookie));
    imCheck($imAuth->user()['id'] === $a, '聊天 cookie 可访问聊天会话');

    $context->bind($originRequest('GET', '/api/im/session', $studioCookie));
    $studioOnIm = false;
    try {
        $imAuth->user();
    } catch (Loom\HttpError $error) {
        $studioOnIm = $error->status === 401;
    }
    imCheck($studioOnIm, '后台 cookie 不能当作聊天会话');

    $context->bind($originRequest('POST', '/api/im/logout', $chatCookie, $chat['csrf'], '{}'));
    $logout = $imAuth->logout();
    imCheck($logout['cookie'] !== '', '聊天登出返回清除 cookie');

    $context->bind($originRequest('GET', '/api/im/session', $chatCookie));
    $chatGone = false;
    try {
        $imAuth->user();
    } catch (Loom\HttpError $error) {
        $chatGone = $error->status === 401;
    }
    imCheck($chatGone, '聊天登出后聊天会话失效');

    $context->bind($originRequest('GET', '/api/admin/session', $studioCookie));
    imCheck($studioAuth->user()['id'] === $a, '聊天登出不影响后台会话');

    $admin = $scope->get(App\Services\Access\Admin::class);
    $admin->enroll($a);
    $admin->enroll($b);

    $contacts = $im->contacts($a, $users[1]['email']);
    imCheck(count($contacts['items']) >= 1, '联系人搜索');

    $orphanEmail = 'im-orphan-' . bin2hex(random_bytes(4)) . '@example.test';
    $orphanId = (int)$db->execute('INSERT INTO loom_users(name,email,password) VALUES(?,?,?)', ['Orphan', $orphanEmail, password_hash($password, PASSWORD_BCRYPT, ['cost' => 4])])['id'];
    $orphanVisible = false;
    foreach ($im->contacts($a, $orphanEmail)['items'] as $item) {
        if ((int)$item['id'] === $orphanId) {
            $orphanVisible = true;
        }
    }
    imCheck(!$orphanVisible, '无身份账户的用户不出现在联系人');
    $db->execute('DELETE FROM loom_users WHERE id=?', [$orphanId]);

    $conversation = $im->createConversation($a, 'direct', null, [$b]);
    $conversationId = (int)$conversation['id'];
    imCheck(isset($conversation['peer']['id']) && (int)$conversation['peer']['id'] === $b, '直聊列表含对方信息');

    $listed = $im->conversations($a);
    imCheck($listed['items'][0]['unread_count'] >= 0 && array_key_exists('last_message', $listed['items'][0]), '会话列表字段补齐');

    $group = $im->createConversation($a, 'group', '集成测试群', [$b]);
    $groupId = (int)$group['id'];
    $detail = $im->conversation($groupId, $a);
    imCheck($detail['members'][0]['role'] === 'owner', '群创建者为群主');

    $renameDenied = false;
    try {
        $im->renameGroup($groupId, $b, '非法改名');
    } catch (Loom\HttpError $error) {
        $renameDenied = $error->status === 403;
    }
    imCheck($renameDenied, '非群主不能改名');
    $im->renameGroup($groupId, $a, '新群名');
    imCheck($im->conversation($groupId, $a)['title'] === '新群名', '群主可改名');

    $forbidden = false;
    try {
        $im->sendMessage($conversationId, max($a, $b) + 100000, 'hello');
    } catch (Loom\HttpError $error) {
        $forbidden = $error->status === 403;
    }
    imCheck($forbidden, '非成员不能发消息');

    $hub = $scope->get(Loom\WebSocket\Hub::class);
    $channel = App\Services\Im\ImMessaging::conversationChannel($conversationId);
    $message = null;
    Loom\Redis::using(static function ($client) use ($hub, $channel, $im, $conversationId, $a, &$message): void {
        $cursor = $hub->tail($client, $channel);
        $message = $im->sendMessage($conversationId, $a, 'integration ping');
        $events = $hub->read($client, [$channel => $cursor], 100, 10);
        imCheck($events !== [], 'Hub 推送会话消息');
    });
    imCheck($message['body'] === 'integration ping', '发送后持久化');

    $unreadA = null;
    $unreadB = null;
    foreach ($im->conversations($a)['items'] as $item) {
        if ((int)$item['id'] === $conversationId) {
            $unreadA = (int)$item['unread_count'];
        }
    }
    foreach ($im->conversations($b)['items'] as $item) {
        if ((int)$item['id'] === $conversationId) {
            $unreadB = (int)$item['unread_count'];
        }
    }
    imCheck($unreadA === 0, '发送者未读数为 0');
    imCheck($unreadB !== null && $unreadB >= 1, '接收者有未读消息');

    $history = $im->messages($conversationId, $a, 1);
    imCheck(count($history['items']) >= 1 && isset($history['items'][0]['sender_name']), '历史消息含发送者');

    $im->markRead($conversationId, $a, (int)$message['id']);
    imCheck($im->conversation($conversationId, $a)['unread_count'] === 0, 'HTTP 已读');

    $ownerLeaveDenied = false;
    try {
        $im->removeMember($groupId, $a, $a);
    } catch (Loom\HttpError $error) {
        $ownerLeaveDenied = $error->status === 422;
    }
    imCheck($ownerLeaveDenied, '群主不能直接退群');

    $im->dissolve($groupId, $a);
    $gone = false;
    try {
        $im->conversation($groupId, $a);
    } catch (Loom\HttpError $error) {
        $gone = in_array($error->status, [403, 404], true);
    }
    imCheck($gone, '解散后会话不可访问');

    $router = $app->router();
    imCheck($router->match('GET', '/api/im/socket')->websocket, 'IM WebSocket 路由已登记');
    imCheck($router->match('POST', '/api/im/login')->status === 200, '聊天登录路由已登记');
    imCheck($router->match('GET', '/api/admin/im/conversations')->status === 404, '旧 /api/admin/im 已移除');

    echo "loom_im_test: OK\n";
}
