<?php

declare(strict_types=1);

namespace Loom\Console\Commands;

/**
 * 检查运行时、执行迁移，再在 Host 里启动 HTTP 与 WebSocket 服务。后台服务（含定时器）只在这个进程里运行。
 */
final class ServeCommand extends \Loom\Console\Command
{
    protected string $signature = 'serve';
    protected string $description = '启动 HTTP 与 WebSocket 服务';

    public function __construct(
        private \Loom\Doctor $doctor,
        private \Loom\Migrations $migrations,
        private \Loom\Host $host,
        private \Loom\HttpServer $server,
        private \Loom\StopSignal $stopSignal,
    ) {
    }

    public function handle(): int
    {
        $this->doctor->check();
        $this->migrations->migrate();
        $this->stopSignal->listen();
        $server = $this->server;
        $this->host->run(static function () use ($server): void {
            $server->run();
        });
        return 0;
    }
}
