<?php

declare(strict_types=1);

namespace Loom\Results;

/**
 * 承接 COUNT 投影的一行。total 由查询填入，这个类自己不查库。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $row = new CountResult();
 */
final class CountResult
{
    public int $total;

    /**
     * 将聚合计数规范为整数数组。
     *
     * 参数：无。
     *
     * @return array 含整数 total 的数组。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $data = $row->toArray();
     */
    public function toArray(): array
    {
        return ['total' => (int)$this->total];
    }
}
