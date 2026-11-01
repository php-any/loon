<?php

declare(strict_types=1);

namespace Loom\Results;

/**
 * 接收按任务状态分组的聚合投影，state 和 total 由数据库填充。
 *
 * 构造参数：无，本类型未声明构造参数。
 *
 * 返回值：使用 new 构造时得到 StateCount 实例；注册到容器时由容器管理实例的创建与复用。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $rows = \Loom\Models\Job::countsByState($database);
 */
final class StateCount
{
    public string $state;
    public int $total;

    /**
     * 输出状态名称和规范化后的计数。
     *
     * 参数：无。
     *
     * @return array 含 state 字符串和 total 整数的数组。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $data = $row->toArray();
     */
    public function toArray(): array
    {
        return ['state' => $this->state, 'total' => (int)$this->total];
    }
}
