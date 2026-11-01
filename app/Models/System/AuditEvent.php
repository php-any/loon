<?php

declare(strict_types=1);

namespace App\Models\System;

#[\Database\Annotation\Table('admin_audit')]
final class AuditEvent extends \Loom\Model
{
    public static function listing(\Loom\Database $database, int $page, string $search): array
    {
        $items = [];
        $pattern = '%' . $search . '%';
        foreach (self::using($database)->native()->where('action LIKE ? OR target LIKE ?', $pattern, $pattern)
            ->orderBy('id DESC')->limit(25)->offset((max(1, min(100000, $page)) - 1) * 25)->get() as $row) {
            $items[] = $row->toArray();
        }
        $count = self::project($database, \Loom\Results\CountResult::class)->where('action LIKE ? OR target LIKE ?', $pattern, $pattern)->select('COUNT(*) AS total')->first();
        return ['items' => $items, 'total' => (int)$count->total];
    }


    #[\Database\Annotation\Id]
    #[\Database\Annotation\GeneratedValue]
    #[\Database\Annotation\Column('id', false)]
    public int $id;

    #[\Database\Annotation\Column('actor_id', true)]
    public ?int $actor_id = null;

    #[\Database\Annotation\Column('action', false)]
    public string $action;

    #[\Database\Annotation\Column('target', false)]
    public string $target;

    #[\Database\Annotation\Column('detail', false)]
    public string $detail;

    #[\Database\Annotation\GeneratedValue]
    #[\Database\Annotation\Column('created', false)]
    public int $created;
}
