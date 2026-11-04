<?php

declare(strict_types=1);

namespace Loom\Results;

/**
 * 承接按任务状态分组的一行。state 和 total 由查询填入，这个类自己不查库。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $row = new StateCount();
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
