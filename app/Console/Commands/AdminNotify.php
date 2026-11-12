<?php

declare(strict_types=1);

namespace App\Console\Commands;

#[\Cli\Annotation\Command]
final class AdminNotify extends \Loom\Console\Command
{
    private \Loom\Config $config;
    private \Loom\Database $database;

    private \App\Services\Notifications\Notifications $notifications;

    public function __construct(\Loom\Config $config, \Loom\Database $database, \App\Services\Notifications\Notifications $notifications)
    {
        $this->config = $config;
        $this->database = $database;

        $this->notifications = $notifications;
    }

    protected string $signature = 'admin:notify {--once : 只扫描一次并投递一条通知}';
    protected string $description = '收集任务失败事件并异步发送邮件和 Webhook 通知';
    public function handle(): int
    {
        $pulse = new \Loom\RuntimePulse($this->config, 'notifications', 2);
        do {
            $pulse->beat();
            $this->notifications->collectFailures();
            $result = $this->notifications->deliver();
            if ($result['state'] !== 'empty') {
                $this->json($result);
            }
            if ($this->option('once')) {
                return $result['state'] === 'failed' ? 1 : 0;
            }
            sleep(2);
        } while (true);
    }
}
