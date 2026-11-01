<?php

declare(strict_types=1);

namespace App\Models\Access;

#[\Database\Annotation\Table('admin_roles')]
final class Role extends \Loom\Model
{
    /** 串行处理内置管理员角色相关的权限变更。 */
    public static function lockAdministrator(\Loom\Database $database): void
    {
        $database->query('SELECT id FROM admin_roles WHERE id=1 FOR UPDATE');
    }


    public static function forUser(\Loom\Database $database, int $user): array
    {
        return self::using($database)->native()->select('admin_roles.*')
            ->join('JOIN admin_user_roles ON admin_user_roles.role_id=admin_roles.id')
            ->where('admin_user_roles.user_id=?', $user)->get();
    }

    #[\Database\Annotation\Id]
    #[\Database\Annotation\GeneratedValue]
    #[\Database\Annotation\Column('id', false)]
    public int $id;

    #[\Database\Annotation\Column('name', false)]
    public string $name;

    #[\Database\Annotation\Column('permissions', false)]
    public string $permissions = '[]';

    #[\Database\Annotation\Column('builtin', false)]
    public int $builtin = 0;
}
