<?php

declare(strict_types=1);

namespace Loom;

/**
 * 读出后台心跳，并按进程名和周期判断现在是否还算活跃。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $status = new RuntimeStatus();
 */
final class RuntimeStatus
{
    /**
     * 按名称读取心跳并附加 active；queue/notifications 使用 40 秒窗口，其他取 10 秒与三倍周期的较大值。
     * 读取使用当前作用域的数据库。
     *
     * 参数：无。
     *
     * @return array 包含原心跳字段及 active 布尔值的记录列表。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $rows = $status->snapshot();
     */
    public function snapshot(): array
    {
        $rows = Models\RuntimePulseRecord::orderBy('name')->rows();
        foreach ($rows as &$row) {
            // 队列任务可能运行长达 30 秒才会发送下一次心跳。
            $ttl = in_array($row['name'], ['queue', 'notifications'], true) ? 40 : max(10, (int)$row['interval_seconds'] * 3);
            $row['active'] = time() - (int)$row['seen'] <= $ttl;
        }
        return $rows;
    }
}
