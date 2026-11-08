<?php

declare(strict_types=1);

namespace Loom;

/**
 * 记住一个后台进程的名字、启动时间和心跳节拍。写库时另开连接，不进调用方的事务。
 *
 * @example $pulse = new RuntimePulse($config, 'queue', 1);
 */
final class RuntimePulse
{
    private int $started;
    private int $ticks = 0;

    /**
     * 保存配置、进程名和间隔，并把启动时间记为现在。这里不写心跳。
     *
     * @param Config $config 每次 beat 用它另建一个 Database。
     * @param string $name 心跳行使用的进程名，例如 queue。
     * @param int $interval 预期节拍间隔，单位秒，原样写入。
     */
    public function __construct(private readonly Config $config, private string $name, private int $interval)
    {
        $this->started = time();
    }

    /**
     * 递增节拍并更新心跳记录。用独立的 Database 写入，不参与调用方的事务。
     *
     * @example $pulse->beat();
     */
    public function beat(): void
    {
        $database = new Database($this->config);
        try {
            Models\RuntimePulseRecord::using($database)->upsert([
                'name' => $this->name, 'started' => $this->started, 'seen' => time(),
                'ticks' => ++$this->ticks, 'interval_seconds' => $this->interval,
            ], ['name'], ['started', 'seen', 'ticks', 'interval_seconds']);
        } finally {
            $database->close();
        }
    }
}
