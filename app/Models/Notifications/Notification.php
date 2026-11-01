<?php

declare(strict_types=1);

namespace App\Models\Notifications;

#[\Database\Annotation\Table('admin_notifications')]
final class Notification extends \Loom\Model
{
    public static function message(\Loom\Database $database, int $id): array
    {
        $row = self::project($database, \App\Queries\Notifications\Message::class)
            ->select('admin_notifications.id,admin_notifications.event_key,admin_notifications.user_id,admin_notifications.title,admin_notifications.body,loom_users.email')
            ->join('JOIN loom_users ON loom_users.id=admin_notifications.user_id')
            ->where('admin_notifications.id=?', $id)->first();
        if ($row === null) {
            throw new \Loom\HttpError(404, '通知不存在');
        }
        return $row->toArray();
    }


    #[\Database\Annotation\Id]
    #[\Database\Annotation\GeneratedValue]
    #[\Database\Annotation\Column('id', false)]
    public int $id;

    #[\Database\Annotation\Column('event_key', false)]
    public string $event_key;

    #[\Database\Annotation\Column('user_id', false)]
    public int $user_id;

    #[\Database\Annotation\Column('title', false)]
    public string $title;

    #[\Database\Annotation\Column('body', false)]
    public string $body;

    #[\Database\Annotation\Column('read_at', true)]
    public ?int $read_at = null;

    #[\Database\Annotation\GeneratedValue]
    #[\Database\Annotation\Column('created', false)]
    public int $created;
}
