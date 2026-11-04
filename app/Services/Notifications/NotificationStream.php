<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use Loom\Database;
use Loom\Redis;

final class NotificationStream
{
    private \Loom\Config $config;

    public function __construct(\Loom\Config $config)
    {
        $this->config = $config;
    }

    public function key(int $user): string
    {
        // 隔离共享 Redis 服务器的应用和数据库。键中不包含凭据。
        return Redis::key('notifications:' . hash('sha256', (new Database($this->config))->pdoDsn()) . ':' . $user);
    }

    public function publish(int $user, int $notification): void
    {
        Redis::using(function ($client) use ($user, $notification): void {
            $key = $this->key($user);
            // 流是有容量限制的唤醒日志；数据库仍是权威数据源。
            Redis::execute($client, ['XADD', $key, 'MAXLEN', '~', '1000', '*', 'notification_id', (string)$notification]);
            Redis::execute($client, ['EXPIRE', $key, '86400']);
        });
    }

    public function tail(\Redis $client, int $user): string
    {
        $events = Redis::execute($client, ['XREVRANGE', $this->key($user), '+', '-', 'COUNT', '1']);
        return $events[0][0] ?? '0-0';
    }

    public function wait(\Redis $client, int $user, string $cursor): ?string
    {
        $streams = Redis::execute($client, ['XREAD', 'BLOCK', '1000', 'COUNT', '100', 'STREAMS', $this->key($user), $cursor]);
        if ($streams === null || $streams === []) {
            return null;
        }
        $events = $streams[0][1];
        return $events[count($events) - 1][0];
    }
}
