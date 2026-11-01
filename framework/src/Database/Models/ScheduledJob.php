<?php

declare(strict_types=1);

namespace Loom\Models;

/**
 * 保存周期或 Cron 计划及下次执行时间，映射到 loom_schedules；继承 Model 的查询方法，实例本身不自动持久化。
 *
 * 构造参数：本声明未定义构造参数；继承类型沿用父类的构造约定。
 *
 * 返回值：使用 new 构造时得到 ScheduledJob 实例；注册到容器时由容器管理实例的创建与复用。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $rows = \Loom\Models\ScheduledJob::using($database)->limit(10)->rows();
 */
#[\Database\Annotation\Table('loom_schedules')]
final class ScheduledJob extends \Loom\Model
{
    /**
     * 按编号 SELECT FOR UPDATE 锁定计划，调用方负责持有事务直到更新提交。
     *
     * @param \Loom\Database $database 当前作用域的数据库服务，负责连接、查询及事务。
     * @param int $id 计划记录主键。
     * @return ?array 计划记录；不存在时为 null。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $row = ScheduledJob::lockById($database, 1);
     */
    public static function lockById(\Loom\Database $database, int $id): ?array
    {
        return $database->query('SELECT * FROM loom_schedules WHERE id=? FOR UPDATE', [$id])[0] ?? null;
    }


    /**
     * 插入或更新 Cron 计划；表达式及时区不变时保留原 next_run。
     *
     * @param \Loom\Database $database 当前作用域的数据库服务，负责连接、查询及事务。
     * @param string $name 计划唯一名称。
     * @param string $kind 执行时投递的 Job 种类。
     * @param string $payload 已经编码为 JSON 的任务载荷字符串。
     * @param int $owner 任务所属用户编号。
     * @param int $next 下次运行的 Unix 时间戳，单位秒。
     * @param string $expression 五字段 Cron 表达式，应在调用前完成校验。
     * @param string $timezone 用于 Cron 的明确 IANA 时区。
     * @return void 无返回值。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example ScheduledJob::storeCron($database, 'report', 'report.build', '{}', 1, $next, '0 9 * * *', 'Asia/Shanghai');
     */
    public static function storeCron(\Loom\Database $database, string $name, string $kind, string $payload, int $owner, int $next, string $expression, string $timezone): void
    {
        $database->execute('INSERT INTO loom_schedules(name,kind,payload,owner,interval_seconds,next_run,expression,timezone) VALUES(?,?,?,?,0,?,?,?) ON DUPLICATE KEY UPDATE kind=VALUES(kind),payload=VALUES(payload),owner=VALUES(owner),interval_seconds=0,next_run=CASE WHEN loom_schedules.expression=VALUES(expression) AND loom_schedules.timezone=VALUES(timezone) THEN loom_schedules.next_run ELSE VALUES(next_run) END,expression=VALUES(expression),timezone=VALUES(timezone)', [$name, $kind, $payload, $owner, $next, $expression, $timezone]);
    }

    /**
     * 保存固定间隔计划；间隔不变时保留下次时间，切换模式时清除 Cron 字段。
     *
     * @param \Loom\Database $database 当前作用域的数据库服务，负责连接、查询及事务。
     * @param string $name 计划唯一名称。
     * @param string $kind 执行时投递的 Job 种类。
     * @param string $payload 已经编码为 JSON 的任务载荷字符串。
     * @param int $owner 任务所属用户编号。
     * @param int $seconds 固定运行间隔，单位秒，应由上层先校验。
     * @param int $next 下次运行的 Unix 时间戳，单位秒。
     * @return void 无返回值。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example ScheduledJob::storeInterval($database, 'report', 'report.build', '{}', 1, 60, time() + 60);
     */
    public static function storeInterval(\Loom\Database $database, string $name, string $kind, string $payload, int $owner, int $seconds, int $next): void
    {
        $database->execute('INSERT INTO loom_schedules(name,kind,payload,owner,interval_seconds,next_run) VALUES(?,?,?,?,?,?) ON DUPLICATE KEY UPDATE kind=VALUES(kind),payload=VALUES(payload),owner=VALUES(owner),next_run=CASE WHEN loom_schedules.expression IS NOT NULL OR loom_schedules.interval_seconds<>VALUES(interval_seconds) THEN VALUES(next_run) ELSE loom_schedules.next_run END,interval_seconds=VALUES(interval_seconds),expression=NULL,timezone=NULL', [$name, $kind, $payload, $owner, $seconds, $next]);
    }

    /**
     * 在调用方事务内锁定最多 100 条已到期的启用计划，跳过其他工作进程已锁定的记录。
     *
     * @param \Loom\Database $database 当前作用域的数据库服务，负责连接、查询及事务。
     * @param int $now 用于筛选到期计划的 Unix 时间戳，单位秒。
     * @return array 可由当前工作进程推进的计划行列表。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $due = ScheduledJob::due($database, time());
     */
    public static function due(\Loom\Database $database, int $now): array
    {
        return $database->query('SELECT * FROM loom_schedules WHERE enabled=1 AND next_run<=? ORDER BY name LIMIT 100 FOR UPDATE SKIP LOCKED', [$now]);
    }

    #[\Database\Annotation\Id]
    #[\Database\Annotation\GeneratedValue]
    #[\Database\Annotation\Column('id', false)]
    public int $id;

    #[\Database\Annotation\Column('name', false)]
    public string $name;

    #[\Database\Annotation\Column('kind', false)]
    public string $kind;

    #[\Database\Annotation\Column('payload', false)]
    public string $payload;

    #[\Database\Annotation\Column('owner', false)]
    public int $owner;

    #[\Database\Annotation\Column('interval_seconds', false)]
    public int $interval_seconds;

    #[\Database\Annotation\Column('next_run', false)]
    public int $next_run;

    #[\Database\Annotation\Column('expression', true)]
    public ?string $expression = null;

    #[\Database\Annotation\Column('timezone', true)]
    public ?string $timezone = null;

    #[\Database\Annotation\Column('enabled', false)]
    public int $enabled = 1;
}
