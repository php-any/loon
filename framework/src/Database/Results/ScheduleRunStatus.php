<?php

declare(strict_types=1);

namespace Loom\Results;

/**
 * 承接调度记录和任务状态连表后的一行。字段由查询填入，这个类自己不查库。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $row = new ScheduleRunStatus();
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
