<?php

declare(strict_types=1);

namespace Loom\Models;

/**
 * 关联一次调度触发与实际队列任务，映射到 loom_schedule_runs；继承 Model 的查询方法，实例本身不自动持久化。
 *
 * 构造参数：本声明未定义构造参数；继承类型沿用父类的构造约定。
 *
 * 返回值：使用 new 构造时得到 ScheduleRun 实例；注册到容器时由容器管理实例的创建与复用。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $rows = \Loom\Models\ScheduleRun::using($database)->limit(10)->rows();
 */
#[\Database\Annotation\Table('loom_schedule_runs')]
final class ScheduleRun extends \Loom\Model
{
    /**
     * 读取最近 25 条调度及关联任务记录，可按计划编号筛选。
     *
     * @param \Loom\Database $database 当前作用域的数据库服务，负责连接、查询及事务。
     * @param ?int $scheduleId 计划主键；null 查询所有计划的最近记录。默认值为 null。
     * @return array 调度状态列表；未指定计划时省略 job_id 和 attempts。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $runs = ScheduleRun::recent($database, 1);
     */
    public static function recent(\Loom\Database $database, ?int $scheduleId = null): array
    {
        $query = self::project($database, \Loom\Results\ScheduleRunStatus::class)
            ->select('loom_schedule_runs.id,loom_schedule_runs.schedule_name,loom_schedule_runs.created,loom_jobs.id AS job_id,loom_jobs.state,loom_jobs.attempts')
            ->join('JOIN loom_jobs ON loom_jobs.id=loom_schedule_runs.job_id');
        if ($scheduleId !== null) {
            $query = $query->where('loom_schedule_runs.schedule_name=(SELECT name FROM loom_schedules WHERE id=?)', $scheduleId);
        }
        $rows = [];
        foreach ($query->orderBy('loom_schedule_runs.id DESC')->limit(25)->get() as $row) {
            $data = $row->toArray();
            if ($scheduleId === null) {
                unset($data['job_id'], $data['attempts']);
            }
            $rows[] = $data;
        }
        return $rows;
    }


    #[\Database\Annotation\Id]
    #[\Database\Annotation\GeneratedValue]
    #[\Database\Annotation\Column('id', false)]
    public int $id;

    #[\Database\Annotation\Column('schedule_name', false)]
    public string $schedule_name;

    #[\Database\Annotation\Column('job_id', false)]
    public int $job_id;

    #[\Database\Annotation\Column('created', false)]
    public int $created;
}
