<?php

declare(strict_types=1);

namespace Loom\Models;

/**
 * loom_schedule_runs 的一行。把一次调度触发记到对应的队列任务上。new 出来的对象不会自己写库。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $run = new ScheduleRun();
 */
#[\Database\Annotation\Table('loom_schedule_runs')]
final class ScheduleRun extends \Loom\Model
{
    /**
     * 读取最近 25 条调度及关联任务记录，可按计划编号筛选。
     *
     * @param \Loom\Database $database 做这次调度记录连接查询的数据库。
     * @param ?int $scheduleId 只看这个计划主键时传入；null 表示最近的各次触发，并且结果里去掉 job_id 和 attempts。默认值为 null。
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
