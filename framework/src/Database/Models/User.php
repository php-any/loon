<?php

declare(strict_types=1);

namespace Loom\Models;

/**
 * loom_users 的一行。保存显示名、邮箱、密码散列和 auth_version。new 出来的对象不会自己写库。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $user = new User();
 */
#[\Database\Annotation\Table('loom_users')]
final class User extends \Loom\Model
{
    /**
     * 在 SQL 中原子递增账号 auth_version，使旧会话在下次验证时失效；参与当前连接事务。
     *
     * @param \Loom\Database $database 执行这条 auth_version 自增的数据库。
     * @param int $id 要让旧会话失效的用户主键。
     * @return void 无返回值。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example User::revokeSessions($database, 1);
     */
    public static function revokeSessions(\Loom\Database $database, int $id): void
    {
        $database->execute('UPDATE loom_users SET auth_version=auth_version+1 WHERE id=?', [$id]);
    }

    #[\Database\Annotation\Id]
    #[\Database\Annotation\GeneratedValue]
    #[\Database\Annotation\Column('id', false)]
    public int $id;

    #[\Database\Annotation\Column('name', false)]
    public string $name;

    #[\Database\Annotation\Column('email', false)]
    public string $email;

    #[\Database\Annotation\Column('password', false)]
    public string $password;

    #[\Database\Annotation\Column('auth_version', false)]
    public int $auth_version = 0;
}
