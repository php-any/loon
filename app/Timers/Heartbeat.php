<?php

declare(strict_types=1);

namespace App\Timers;

use Loom\Timer;

#[Timer(5)]
final class Heartbeat
{
    private \Loom\Config $config;
    private \Loom\Database $database;

    public function __construct(\Loom\Config $config, \Loom\Database $database)
    {
        $this->config = $config;
        $this->database = $database;
    }

    private ?\Loom\RuntimePulse $pulse = null;

    public function __invoke(): void
    {
        $this->pulse ??= new \Loom\RuntimePulse($this->config, $this->database, 'timer', 5);
        $this->pulse->beat();
        echo '[' . date('Y-m-d H:i:s') . "] [INFO] timer: service is running\n";
    }
}
