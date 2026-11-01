<?php

declare(strict_types=1);

namespace Loom;

// 使用专用连接，避免后台遥测参与请求事务。
/**
 * 记录指定后台进程的启动时间和心跳计数，每次写入使用专用数据库连接，避免参与请求事务。
 *
 * 构造参数：
 * - $config（\Loom\Config）：项目配置实例，提供配置读取及项目路径解析。
 * - $database（\Loom\Database）：注入的作用域数据库服务；beat 的遥测写入仍另建专用连接。
 * - $name（string）：后台进程标识，例如 queue 或 scheduler。
 * - $interval（int）：预期心跳间隔，单位秒，用于状态展示。
 *
 * 返回值：使用 new 构造时得到 RuntimePulse 实例；注册到容器时由容器管理实例的创建与复用。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $pulse = new RuntimePulse($config, $database, 'queue', 1); $pulse->beat();
 */
final class RuntimePulse
{
    private \Loom\Config $config;
    private \Loom\Database $database;

    private int $started;
    private int $ticks = 0;

    /**
     * 初始化 RuntimePulse 实例及其依赖。记录指定后台进程的启动时间和心跳计数，每次写入使用专用数据库连接，避免参与请求事务。
     *
     * @param \Loom\Config $config 项目配置实例，提供配置读取及项目路径解析。
     * @param \Loom\Database $database 注入的作用域数据库服务；beat 的遥测写入仍另建专用连接。
     * @param string $name 后台进程标识，例如 queue 或 scheduler。
     * @param int $interval 预期心跳间隔，单位秒，用于状态展示。
     * @return void 无直接返回值；通过 new 表达式取得初始化后的实例。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $pulse = new RuntimePulse($config, $database, 'queue', 1); $pulse->beat();
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
