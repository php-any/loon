<?php

declare(strict_types=1);

namespace Loom\Models;

/**
 * 保存框架账号、密码散列和会话撤销版本，映射到 loom_users；继承 Model 的查询方法，实例本身不自动持久化。
 *
 * 构造参数：本声明未定义构造参数；继承类型沿用父类的构造约定。
 *
 * 返回值：使用 new 构造时得到 User 实例；注册到容器时由容器管理实例的创建与复用。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $rows = \Loom\Models\User::using($database)->limit(10)->rows();
 */
#[\Database\Annotation\Table('loom_users')]
final class User extends \Loom\Model
{
    /**
     * 在 SQL 中原子递增账号 auth_version，使旧会话在下次验证时失效；参与当前连接事务。
     *
     * @param \Loom\Database $database 当前作用域的数据库服务，负责连接、查询及事务。
     * @param int $id 当前处理对象的整数标识。
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
