<?php

declare(strict_types=1);

namespace Loom;

// 使用专用连接，避免后台遥测参与请求事务。
/**
 * 记住一个后台进程的名字、启动时间和心跳节拍。写库时另开连接，不进调用方的事务。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $pulse = new RuntimePulse($config, $database, 'queue', 1);
 */
final class RuntimePulse
{
    private \Loom\Config $config;
    private \Loom\Database $database;

    private int $started;
    private int $ticks = 0;

    /**
     * 保存配置、数据库、进程名和间隔，并把启动时间记为现在。节拍从 0 开始。这里不写心跳。
     *
     * @param \Loom\Config $config 以后用它另建一条数据库连接。
     * @param \Loom\Database $database 只保存。beat 不读取它，避免写进调用方的事务。
     * @param string $name 心跳行使用的进程名，例如 queue。
     * @param int $interval 预期节拍间隔，单位秒，以后原样写入。
     * @return void 不返回值。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $pulse = new RuntimePulse($config, $database, 'queue', 1);
     */
    public function __construct(\Loom\Config $config, \Loom\Database $database, private string $name, private int $interval)
    {
        $this->config = $config;
        $this->database = $database;

        $this->started = time();
    }

    /**
     * 递增节拍并更新心跳记录，使用独立连接写入后立即关闭。
     *
     * 参数：无。
     *
     * @return void 无返回值，更新 loom_runtime_pulses。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $pulse->beat();
     */
    public function beat(): void
    {
        $database = new Database($this->config);
        try {
            // 显式绑定，确保遥测处于当前请求事务之外。
            Models\RuntimePulseRecord::using($database)->upsert([
                'name' => $this->name, 'started' => $this->started, 'seen' => time(),
                'ticks' => ++$this->ticks, 'interval_seconds' => $this->interval,
            ], ['name'], ['started', 'seen', 'ticks', 'interval_seconds']);
        } finally {
            $database->close();
        }
    }


}
