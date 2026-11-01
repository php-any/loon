<?php

declare(strict_types=1);

namespace Loom\Results;

/**
 * 接收 COUNT 聚合投影，total 由数据库填充；无构造参数，不自行查询数据库。
 *
 * 构造参数：无，本类型未声明构造参数。
 *
 * 返回值：使用 new 构造时得到 CountResult 实例；注册到容器时由容器管理实例的创建与复用。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $row = new CountResult(); $row->total = 3; $data = $row->toArray();
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
