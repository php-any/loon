<?php

declare(strict_types=1);

namespace App\Console\Timers;

use Loom\Timer;

// 单例：节拍计数要跨 tick 保留。
#[Timer(5)]
#[\Container\Annotation\Singleton]
final class Heartbeat
{
    private \Loom\RuntimePulse $pulse;

    public function __construct(\Loom\Config $config)
    {
        $this->pulse = new \Loom\RuntimePulse($config, 'timer', 5);
    }

    public function __invoke(): void
    {
        $this->pulse->beat();
        echo '[' . date('Y-m-d H:i:s') . "] [INFO] timer: service is running\n";
    }
}
