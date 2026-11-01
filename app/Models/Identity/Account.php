<?php

declare(strict_types=1);

namespace App\Models\Identity;

#[\Database\Annotation\Table('admin_accounts')]
final class Account extends \Loom\Model
{
    private static function search(\Loom\Database $database, string $result, string $search): \Database\DB
    {
        return \Database\DB::model($result, $database->modelConnectionName())->table('loom_users')
            ->join('LEFT JOIN admin_accounts ON admin_accounts.user_id=loom_users.id')
            ->where('loom_users.name LIKE ? OR loom_users.email LIKE ?', '%' . $search . '%', '%' . $search . '%');
    }

    public static function listing(\Loom\Database $database, int $page, string $search): array
    {
        $items = [];
        foreach (self::search($database, \App\Queries\Identity\AccountListItem::class, $search)
            ->select('loom_users.id,loom_users.name,loom_users.email,COALESCE(admin_accounts.enabled,1) AS enabled,admin_accounts.verified_at')
            ->orderBy('loom_users.id DESC')->limit(25)->offset((max(1, min(100000, $page)) - 1) * 25)->get() as $row) {
            $items[] = $row->toArray();
        }
        $count = self::search($database, \Loom\Results\CountResult::class, $search)->select('COUNT(*) AS total')->first();
        return ['items' => $items, 'total' => (int)$count->total];
    }


    public static function statusForEmail(\Loom\Database $database, string $email): ?array
    {
        // 从用户表开始查询，确保没有账户记录的旧用户仍然可见。
        $row = \Database\DB::model(\App\Queries\Identity\AccountStatus::class, $database->modelConnectionName())
            ->table('loom_users')->select('loom_users.id,admin_accounts.enabled,admin_accounts.verified_at')
            ->join('LEFT JOIN admin_accounts ON admin_accounts.user_id=loom_users.id')
            ->where('loom_users.email=?', $email)->first();
        return $row === null ? null : $row->toArray();
    }


    public static function enabledUserIds(\Loom\Database $database): array
    {
        $ids = [];
        foreach (self::using($database)->native()->select('admin_accounts.*')
            ->join('JOIN loom_users ON loom_users.id=admin_accounts.user_id')
            ->where('admin_accounts.enabled=?', 1)->get() as $account) {
            $ids[] = (int)$account->user_id;
        }
        return $ids;
    }

    #[\Database\Annotation\Id]
    #[\Database\Annotation\Column('user_id', false)]
    public int $user_id;

    #[\Database\Annotation\Column('enabled', false)]
    public int $enabled = 1;

    #[\Database\Annotation\Column('verified_at', true)]
    public ?int $verified_at = null;
}
