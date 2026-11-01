<?php

declare(strict_types=1);

namespace Loom\Results;

/**
 * 接收调度执行记录与任务状态的连接查询结果；由 ORM 填充全部公共字段后转换为数组。
 *
 * 构造参数：无，本类型未声明构造参数。
 *
 * 返回值：使用 new 构造时得到 ScheduleRunStatus 实例；注册到容器时由容器管理实例的创建与复用。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $rows = \Loom\Models\ScheduleRun::recent($database, 1);
 */
final class ScheduleRunStatus
{
    public int $id;
    public string $schedule_name;
    public int $created;
    public int $job_id;
    public string $state;
    public int $attempts;

    /**
     * 把调度和任务投影转换为接口数据，规范数值字段的类型。
     *
     * 参数：无。
     *
     * @return array 含 id、schedule_name、created、job_id、state、attempts 的数组。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $data = $row->toArray();
     */
    public function toArray(): array
    {
        return ['id' => (int)$this->id, 'schedule_name' => $this->schedule_name, 'created' => (int)$this->created, 'job_id' => (int)$this->job_id, 'state' => $this->state, 'attempts' => (int)$this->attempts];
    }
}
