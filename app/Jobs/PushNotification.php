<?php

declare(strict_types=1);

namespace App\Jobs;

#[\Loom\Attributes\Job('notification.push')]
final class PushNotification
{
    private \App\Services\Notifications\NotificationStream $stream;

    public function __construct(\App\Services\Notifications\NotificationStream $stream)
    {
        $this->stream = $stream;
    }

    public function handle(\Loom\Request $request): \Loom\Response
    {
        $job = json_decode($request->body(), true, 64, JSON_THROW_ON_ERROR);
        $id = (int)$job['payload']['notification_id'];
        $notification = \App\Models\Notifications\Notification::whereId($id)->firstOrFail()->toArray();
        if ((int)$notification['user_id'] !== (int)$job['owner']) {
            throw new \LogicException('Notification owner mismatch');
        }
        $this->stream->publish((int)$notification['user_id'], $id);
        // 至少一次的队列投递可能重复唤醒；客户端按通知 ID 去重。
        return \Loom\Response::json(['ok' => true, 'id' => $id]);
    }
}
