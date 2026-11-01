<?php

declare(strict_types=1);

namespace Loom\Models;

/**
 * 保存认证尝试计数及冷却截止时间，映射到 loom_auth_limits；继承 Model 的查询方法，实例本身不自动持久化。
 *
 * 构造参数：本声明未定义构造参数；继承类型沿用父类的构造约定。
 *
 * 返回值：使用 new 构造时得到 AuthLimit 实例；注册到容器时由容器管理实例的创建与复用。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $rows = \Loom\Models\AuthLimit::using($database)->limit(10)->rows();
 */
#[\Database\Annotation\Table('loom_auth_limits')]
final class AuthLimit extends \Loom\Model
{
    /**
     * 锁定冷却记录并尝试占用冷却窗口；调用方必须持有覆盖该操作的事务。
     *
     * @param \Loom\Database $database 当前作用域的数据库服务，负责连接、查询及事务。
     * @param string $key 限流或冷却记录标识，例如邮箱散列或操作范围键。
     * @param int $now 当前 Unix 时间戳，单位秒。
     * @param int $seconds 要占用的冷却时长，单位秒。
     * @return bool 取得窗口返回 true，尚在冷却中返回 false。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $ok = $database->transaction(fn () => AuthLimit::acquireCooldown($database, 'mail:1', time(), 60));
     */
    public static function acquireCooldown(\Loom\Database $database, string $key, int $now, int $seconds): bool
    {
        self::using($database)->insertOrIgnore(['key' => $key, 'attempts' => 0, 'until_at' => 0], ['key']);
        $row = $database->query('SELECT until_at FROM loom_auth_limits WHERE "key"=? FOR UPDATE', [$key])[0];
        if ($row !== null && (int)$row['until_at'] > $now) {
            return false;
        }
        self::using($database)->upsert(['key' => $key, 'attempts' => 1, 'until_at' => $now + $seconds], ['key'], ['until_at']);
        return true;
    }


    /**
     * 在一分钟窗口内原子递增尝试次数，窗口过期则重置；内部自行管理事务。
     *
     * @param \Loom\Database $database 当前作用域的数据库服务，负责连接、查询及事务。
     * @param string $key 限流或冷却记录标识，例如邮箱散列或操作范围键。
     * @param int $now 当前 Unix 时间戳，单位秒。
     * @return int 更新后的尝试次数。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $attempts = AuthLimit::recordAttempt($database, $key, time());
     */
    public static function recordAttempt(\Loom\Database $database, string $key, int $now): int
    {
        $limitSql = 'INSERT INTO loom_auth_limits("key",attempts,until_at) VALUES(?,1,?) ON DUPLICATE KEY UPDATE attempts=CASE WHEN until_at<=? THEN 1 ELSE attempts+1 END,until_at=CASE WHEN until_at<=? THEN VALUES(until_at) ELSE until_at END';
        $limitArgs = [$key, $now + 60, $now, $now];
        $limit = $database->transaction(function () use ($database, $limitSql, $limitArgs, $key): array {
            $database->execute($limitSql, $limitArgs);
            return self::using($database)->where('key', $key)->rows(['attempts']);
        });
        return (int)$limit[0]['attempts'];
    }


    #[\Database\Annotation\Id]
    #[\Database\Annotation\Column('key', false)]
    public string $key;

    #[\Database\Annotation\Column('attempts', false)]
    public int $attempts;

    #[\Database\Annotation\Column('until_at', false)]
    public int $until_at;
}
