<?php

declare(strict_types=1);

namespace App\Models\Access;

#[\Database\Annotation\Table('admin_user_roles')]
final class UserRole extends \Loom\Model
{
    /** 使用单条语句为旧账户分配角色，同时保留已有分配。 */
    public static function ensureMember(\Loom\Database $database, int $user): void
    {
        $database->execute('INSERT INTO admin_user_roles(user_id,role_id) SELECT ?,2 WHERE NOT EXISTS(SELECT 1 FROM admin_user_roles WHERE user_id=?)', [$user, $user]);
    }


    public static function hasActiveAdministrator(\Loom\Database $database): bool
    {
        return self::using($database)->native()->select('admin_user_roles.*')
            ->join('JOIN admin_accounts ON admin_accounts.user_id=admin_user_roles.user_id')
            ->where('admin_user_roles.role_id=? AND admin_accounts.enabled=?', 1, 1)->first() !== null;
    }

    #[\Database\Annotation\Id]
    #[\Database\Annotation\Column('user_id', false)]
    public int $user_id;

    #[\Database\Annotation\Id]
    #[\Database\Annotation\Column('role_id', false)]
    public int $role_id;
}
