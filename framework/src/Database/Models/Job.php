<?php

declare(strict_types=1);

namespace Loom\Models;

/**
 * loom_jobs 的一行。保存任务种类、载荷、租约、次数和结果。new 出来的对象不会自己写库。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $job = new Job();
 */
#[\Database\Annotation\Table('loom_jobs')]
final class Job extends \Loom\Model
{
    /**
     * 按任务状态聚合持久化任务数量。
     *
     * @param \Loom\Database $database 做这次按 state 聚合的数据库。
     * @return array 每项含 state 和 total 的列表。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $counts = Job::countsByState($database);
     */
    public static function countsByState(\Loom\Database $database): array
    {
        $rows = [];
        foreach (self::project($database, \Loom\Results\StateCount::class)->select('state,COUNT(*) AS total')->groupBy('state')->get() as $row) {
            $rows[] = $row->toArray();
        }
        return $rows;
    }


    /**
     * 选取已到期的待处理任务或租约过期任务，仅取尝试次数少于三次的前 100 条。
     *
     * @param \Loom\Database $database 读取待发布任务的数据库。
     * @param int $now 用来和 available、lease_until 比较的秒级时间戳。
     * @return array 可重新发布到 Redis 的任务记录数组列表。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $jobs = Job::readyForRedis($database, time());
     */
    public static function readyForRedis(\Loom\Database $database, int $now): array
    {
        $rows = [];
        foreach (self::using($database)->native()
            ->where("((state='pending' AND available<=?) OR (state='running' AND lease_until<=?)) AND attempts<3", $now, $now)
            ->orderBy('available,id')->limit(100)->get() as $job) {
            $rows[] = $job->toArray();
        }
        return $rows;
    }

    /**
     * 使用任务编号和前次尝试次数进行 CAS，绑定 Redis 租约并把执行次数加一。
     *
     * @param \Loom\Database $database 执行这条 CAS 更新的数据库。
     * @param array $job Redis 认领数据，读取 id、attempt 和 lease。
     * @param int $now 秒级时间戳。新的 lease_until 是它加 60 秒。
     * @return bool 成功更新恰好一行为 true，过期认领为 false。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $accepted = Job::acceptRedisLease($database, $job, time());
     */
    public static function acceptRedisLease(\Loom\Database $database, array $job, int $now): bool
    {
        $changed = $database->execute("UPDATE loom_jobs SET state='running',attempts=attempts+1,lease=?,lease_until=? WHERE id=? AND attempts=? AND ((state='pending' AND available<=?) OR (state='running' AND lease_until<=?))", [$job['lease'], $now + 60, $job['id'], $job['attempt'] - 1, $now, $now]);
        return $changed['affected'] === 1;
    }

    #[\Database\Annotation\Id]
    #[\Database\Annotation\GeneratedValue]
    #[\Database\Annotation\Column('id', false)]
    public int $id;

    #[\Database\Annotation\Column('kind', false)]
    public string $kind;

    #[\Database\Annotation\Column('payload', false)]
    public string $payload;

    #[\Database\Annotation\Column('owner', false)]
    public int $owner;

    #[\Database\Annotation\Column('state', false)]
    public string $state = 'pending';

    #[\Database\Annotation\Column('attempts', false)]
    public int $attempts = 0;

    #[\Database\Annotation\Column('available', false)]
    public int $available;

    #[\Database\Annotation\Column('lease', true)]
    public ?string $lease = null;

    #[\Database\Annotation\Column('lease_until', true)]
    public ?int $lease_until = null;

    #[\Database\Annotation\Column('result', true)]
    public ?string $result = null;

    #[\Database\Annotation\Column('error', true)]
    public ?string $error = null;

    #[\Database\Annotation\Column('created', false)]
    public int $created;
}
