<?php

declare(strict_types=1);

namespace Loom\Models;

/**
 * 保存任务载荷、租约、执行次数和最终结果，作为持久化发件箱，映射到 loom_jobs；继承 Model 的查询方法，实例本身不自动持久化。
 *
 * 构造参数：本声明未定义构造参数；继承类型沿用父类的构造约定。
 *
 * 返回值：使用 new 构造时得到 Job 实例；注册到容器时由容器管理实例的创建与复用。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $rows = \Loom\Models\Job::using($database)->limit(10)->rows();
 */
#[\Database\Annotation\Table('loom_jobs')]
final class Job extends \Loom\Model
{
    /**
     * 按任务状态聚合持久化任务数量。
     *
     * @param \Loom\Database $database 当前作用域的数据库服务，负责连接、查询及事务。
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
     * @param \Loom\Database $database 当前作用域的数据库服务，负责连接、查询及事务。
     * @param int $now 当前 Unix 时间戳，单位秒；新租约固定为 60 秒。
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
     * @param \Loom\Database $database 当前作用域的数据库服务，负责连接、查询及事务。
     * @param array $job Redis 认领数据，至少包含 id、attempt 和 lease。
     * @param int $now 当前 Unix 时间戳，单位秒；新租约固定为 60 秒。
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
