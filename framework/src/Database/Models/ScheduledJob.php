<?php

declare(strict_types=1);

namespace Loom\Models;

/**
 * loom_schedules 的一行。保存计划名、任务种类、间隔或 Cron，以及下次运行时间。new 出来的对象不会自己写库。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $schedule = new ScheduledJob();
 */
#[\Database\Annotation\Table('loom_schedules')]
final class ScheduledJob extends \Loom\Model
{
    /**
     * 按编号 SELECT FOR UPDATE 锁定计划，调用方负责持有事务直到更新提交。
     *
     * @param \Loom\Database $database 执行这条 SELECT ... FOR UPDATE 的数据库。调用方需要已经处在事务里。
     * @param int $id 要锁定的计划主键。
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
     * @param \Loom\Database $database 执行这条 Cron 计划插入或更新的数据库。
     * @param string $name 计划的唯一名称，冲突时按它更新。
     * @param string $kind 到期后要投递的任务种类。
     * @param string $payload 已经编码好的 JSON 载荷。
     * @param int $owner 生成任务时使用的用户编号。
     * @param int $next 新计划或规则变化时写入的下次运行时间，单位秒。规则没变则保留原值。
     * @param string $expression 要保存的五字段 Cron。本方法不再解析它。
     * @param string $timezone 和表达式一起保存的 IANA 时区。
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
     * @param \Loom\Database $database 执行这条间隔计划插入或更新的数据库。
     * @param string $name 计划的唯一名称，冲突时按它更新。
     * @param string $kind 到期后要投递的任务种类。
     * @param string $payload 已经编码好的 JSON 载荷。
     * @param int $owner 生成任务时使用的用户编号。
     * @param int $seconds 要保存的固定间隔，单位秒。本方法不检查范围。
     * @param int $next 新计划、刚从 Cron 改过来或间隔变了时写入的下次运行时间，单位秒。间隔没变则保留原值。
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
     * @param \Loom\Database $database 在调用方事务里锁定到期行的数据库。
     * @param int $now 用来和 next_run 比较的秒级时间戳。
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
