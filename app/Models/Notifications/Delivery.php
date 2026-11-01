<?php

declare(strict_types=1);

namespace App\Models\Notifications;

#[\Database\Annotation\Table('admin_deliveries')]
final class Delivery extends \Loom\Model
{
    public static function countsByState(\Loom\Database $database): array
    {
        $rows = [];
        foreach (self::project($database, \Loom\Results\StateCount::class)->select('state,COUNT(*) AS total')->groupBy('state')->get() as $row) {
            $rows[] = $row->toArray();
        }
        return $rows;
    }


    private static function search(\Loom\Database $database, string $result, string $search): \Database\DB
    {
        return self::project($database, $result)
            ->join('JOIN admin_notifications ON admin_notifications.id=admin_deliveries.notification_id')
            ->where('admin_notifications.title LIKE ?', '%' . $search . '%');
    }

    public static function listing(\Loom\Database $database, int $page, string $search): array
    {
        $items = [];
        foreach (self::search($database, \App\Queries\Notifications\DeliveryListItem::class, $search)
            ->select('admin_deliveries.id,admin_deliveries.notification_id,admin_deliveries.channel,admin_deliveries.state,admin_deliveries.attempts,admin_deliveries.error,admin_deliveries.created,admin_notifications.user_id,admin_notifications.title')
            ->orderBy('admin_deliveries.id DESC')->limit(25)->offset((max(1, min(100000, $page)) - 1) * 25)->get() as $row) {
            $items[] = $row->toArray();
        }
        $count = self::search($database, \Loom\Results\CountResult::class, $search)->select('COUNT(*) AS total')->first();
        return ['items' => $items, 'total' => (int)$count->total];
    }


    /** 原子地选择任务并分配租约；此事务中不执行网络 I/O。 */
    public static function claim(\Loom\Database $database, string $lease): array
    {
        return $database->transaction(function () use ($database, $lease): array {
            self::using($database)->whereState('sending')->where('lease_until', '<=', time())->where('attempts', '>=', 3)->update(['state' => 'failed', 'error' => '投递租约过期']);
            $rows = $database->query("SELECT d.* FROM admin_deliveries d JOIN admin_channels c ON c.channel=d.channel WHERE c.enabled=1 AND ((d.state='pending' AND d.available<=?) OR (d.state='sending' AND d.lease_until<=?)) AND d.attempts<3 ORDER BY d.id LIMIT 1 FOR UPDATE SKIP LOCKED", [time(), time()]);
            if ($rows === []) {
                return [];
            }
            self::using($database)->whereId($rows[0]['id'])->update(['state' => 'sending', 'attempts' => (int)$rows[0]['attempts'] + 1, 'lease' => $lease, 'lease_until' => time() + 60]);
            return self::using($database)->whereId($rows[0]['id'])->rows();
        });
    }


    #[\Database\Annotation\Id]
    #[\Database\Annotation\GeneratedValue]
    #[\Database\Annotation\Column('id', false)]
    public int $id;

    #[\Database\Annotation\Column('notification_id', false)]
    public int $notification_id;

    #[\Database\Annotation\Column('channel', false)]
    public string $channel;

    #[\Database\Annotation\Column('state', false)]
    public string $state = 'pending';

    #[\Database\Annotation\Column('attempts', false)]
    public int $attempts = 0;

    #[\Database\Annotation\GeneratedValue]
    #[\Database\Annotation\Column('available', false)]
    public int $available;

    #[\Database\Annotation\Column('lease', true)]
    public ?string $lease = null;

    #[\Database\Annotation\Column('lease_until', true)]
    public ?int $lease_until = null;

    #[\Database\Annotation\Column('error', true)]
    public ?string $error = null;

    #[\Database\Annotation\GeneratedValue]
    #[\Database\Annotation\Column('created', false)]
    public int $created;
}
