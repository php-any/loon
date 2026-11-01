<?php

declare(strict_types=1);

namespace Loom;

/**
 * 读取后台心跳并根据进程类型及周期计算是否仍然活跃，供运维状态查询。
 *
 * 构造参数：
 * - $database（\Loom\Database）：当前作用域的数据库服务，负责连接、查询及事务。
 *
 * 返回值：使用 new 构造时得到 RuntimeStatus 实例；注册到容器时由容器管理实例的创建与复用。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $status = $services->get(RuntimeStatus::class)->snapshot();
 */
final class RuntimeStatus
{
    private \Loom\Database $database;

    /**
     * 初始化 RuntimeStatus 实例及其依赖。读取后台心跳并根据进程类型及周期计算是否仍然活跃，供运维状态查询。
     *
     * @param \Loom\Database $database 当前作用域的数据库服务，负责连接、查询及事务。
     * @return void 无直接返回值；通过 new 表达式取得初始化后的实例。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $status = $services->get(RuntimeStatus::class)->snapshot();
     */
    public function __construct(\Loom\Database $database)
    {
        $this->database = $database;
    }

    /**
     * 按名称读取心跳并附加 active；queue/notifications 使用 40 秒窗口，其他取 10 秒与三倍周期的较大值。
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
        $rows = Models\RuntimePulseRecord::using($this->database)->orderBy('name')->rows();
        foreach ($rows as &$row) {
            // 队列任务可能运行长达 30 秒才会发送下一次心跳。
            $ttl = in_array($row['name'], ['queue', 'notifications'], true) ? 40 : max(10, (int)$row['interval_seconds'] * 3);
            $row['active'] = time() - (int)$row['seen'] <= $ttl;
        }
        return $rows;
    }
}
