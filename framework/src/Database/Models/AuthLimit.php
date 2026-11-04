<?php

declare(strict_types=1);

namespace Loom\Models;

/**
 * loom_auth_limits 的一行。按键保存尝试次数和冷却截止时间。new 出来的对象不会自己写库。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $limit = new AuthLimit();
 */
#[\Database\Annotation\Table('loom_auth_limits')]
final class AuthLimit extends \Loom\Model
{
    /**
     * 锁定冷却记录并尝试占用冷却窗口；调用方必须持有覆盖该操作的事务。
     *
     * @param \Loom\Database $database 插入、锁定并更新这一行的数据库。调用方必须已经持有事务。
     * @param string $key 冷却行的主键。
     * @param int $now 用来和 until_at 比较的秒级时间戳。
     * @param int $seconds 抢到窗口后，把 until_at 设成 $now 加上这个秒数。
     * @return bool 取得窗口返回 true。until_at 仍晚于 $now 时返回 false。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $ok = AuthLimit::acquireCooldown($database, 'mail:1', time(), 60);
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
     * @param \Loom\Database $database 本方法在它上面自开事务，递增并读回次数。
     * @param string $key 计数行的主键，例如邮箱散列。
     * @param int $now 用来判断一分钟窗口是否已过期的秒级时间戳。窗口过期时 until_at 改成它加 60 秒。
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
