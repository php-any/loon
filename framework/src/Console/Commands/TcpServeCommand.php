<?php

declare(strict_types=1);

namespace Loom\Console\Commands;

/**
 * 启动 TCP JSON 行协议服务。每条消息由 TcpServer 在新作用域里交给 tcp.handler。
 */
final class TcpServeCommand extends \Loom\Console\Command
{
    protected string $signature = 'tcp:serve';
    protected string $description = '启动 TCP JSON 行协议服务';

    public function __construct(private \Loom\TcpServer $server)
    {
    }

    public function handle(): int
    {
        $this->server->run();
        return 0;
    }
}
