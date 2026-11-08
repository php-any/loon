<?php

declare(strict_types=1);

namespace Loom\Console\Commands;

/**
 * 启动 gRPC 服务。每次调用由 GrpcServer 在新作用域里分派。
 */
final class GrpcServeCommand extends \Loom\Console\Command
{
    protected string $signature = 'grpc:serve';
    protected string $description = '启动 gRPC 服务';

    public function __construct(private \Loom\GrpcServer $server)
    {
    }

    public function handle(): int
    {
        $this->server->run();
        return 0;
    }
}
