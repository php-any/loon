<?php

declare(strict_types=1);

namespace App\Models\Notifications;

#[\Database\Annotation\Table('admin_mail')]
final class Mail extends \Loom\Model
{
    /** 事务由调用方管理，包括过期清理。 */
    public static function claim(\Loom\Database $database, int $now): array
    {
        $mail = $database->query("SELECT * FROM admin_mail WHERE (state='pending' AND available<=?) OR (state='sending' AND lease_until<=?) ORDER BY id LIMIT 1 FOR UPDATE SKIP LOCKED", [$now, $now])[0] ?? null;
        if ($mail === null) {
            return [];
        }
        self::using($database)->whereId($mail['id'])->update(['state' => 'sending', 'attempts' => (int)$mail['attempts'] + 1, 'lease_until' => $now + 60]);
        return $mail;
    }

    #[\Database\Annotation\Id]
    #[\Database\Annotation\GeneratedValue]
    #[\Database\Annotation\Column('id', false)]
    public int $id;

    #[\Database\Annotation\Column('recipient', false)]
    public string $recipient;

    #[\Database\Annotation\Column('subject', false)]
    public string $subject;

    #[\Database\Annotation\Column('body', false)]
    public string $body;

    #[\Database\Annotation\Column('state', false)]
    public string $state = 'pending';

    #[\Database\Annotation\Column('attempts', false)]
    public int $attempts = 0;

    #[\Database\Annotation\GeneratedValue]
    #[\Database\Annotation\Column('available', false)]
    public int $available;

    #[\Database\Annotation\Column('lease_until', true)]
    public ?int $lease_until = null;

    #[\Database\Annotation\GeneratedValue]
    #[\Database\Annotation\Column('created', false)]
    public int $created;
}
