<?php

declare(strict_types=1);

namespace App\Http\Controllers;

#[\Loom\Attributes\Controller]
final class NotificationSocketController
{
    private \Loom\Auth $auth;
    private \App\Services\Access\Admin $admin;
    private \App\Services\Notifications\Notifications $notifications;
    private \App\Services\Notifications\NotificationStream $stream;

    public function __construct(\Loom\Auth $auth, \App\Services\Access\Admin $admin, \App\Services\Notifications\Notifications $notifications, \App\Services\Notifications\NotificationStream $stream)
    {
        $this->auth = $auth;
        $this->admin = $admin;
        $this->notifications = $notifications;
        $this->stream = $stream;
    }

    #[\Loom\Attributes\Route('GET', '/api/admin/notifications/socket', '', [\App\Http\Middleware\RequireNotificationSocket::class], true)]
    public function connect(\Loom\Request $request, \Net\Websocket\Conn $connection): void
    {
        $user = (int)$request->attribute('notification_user');
        \Loom\Native\watchWebSocket($connection);
        try {
            \Loom\Redis::using(function ($client) use ($connection, $user): void {
                // 在数据库快照之前获取流游标，消除订阅与快照之间的空隙。
                $cursor = $this->stream->tail($client, $user);
                $connection->writeText(json_encode(['type' => 'notifications', 'data' => $this->notifications->inbox($user, 1)], JSON_THROW_ON_ERROR));
                // 升级成功后由长连接接管，不再受 HTTP 请求计时器控制。
                while (true) {
                    $next = $this->stream->wait($client, $user, $cursor);
                    $current = $this->auth->guard();
                    if ($current['id'] !== $user) {
                        return;
                    }
                    $this->admin->assertActive($user);
                    if ($next !== null) {
                        $cursor = $next;
                        $connection->writeText(json_encode(['type' => 'notifications', 'data' => $this->notifications->inbox($user, 1)], JSON_THROW_ON_ERROR));
                    } else {
                        $connection->writeText('{"type":"heartbeat"}');
                    }
                }
            });
        } catch (\Loom\HttpError $error) {
            try {
                $connection->writeText(json_encode(['type' => in_array($error->status, [401, 403], true) ? 'unauthorized' : 'unavailable'], JSON_THROW_ON_ERROR));
            } catch (\Throwable $closed) {
                // 对端已经断开连接。
            }
        } catch (\Throwable $closed) {
            // 套接字关闭或超时属于预期情况；分发过程始终关闭传输对象。
        }
    }
}
