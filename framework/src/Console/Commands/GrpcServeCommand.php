<?php

declare(strict_types=1);

namespace Loom\Console\Commands;

/**
 * 启动 gRPC 服务并持续监听请求。由控制台容器解析依赖并调用。
 *
 * 构造参数：
 * - $server（\Loom\GrpcServer）：对应协议的服务器实例，run 会进入监听循环。
 *
 * 返回值：使用 new 构造时得到 GrpcServeCommand 实例；注册到容器时由容器管理实例的创建与复用。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $console->run(['grpc:serve']);
 */
final class GrpcServeCommand extends \Loom\Console\Command
{
    protected string $signature = 'grpc:serve';
    protected string $description = '启动 gRPC 服务';

    /**
     * 初始化 GrpcServeCommand 实例及其依赖。启动 gRPC 服务并持续监听请求。由控制台容器解析依赖并调用。
     *
     * @param \Loom\GrpcServer $server 对应协议的服务器实例，run 会进入监听循环。
     * @return void 无直接返回值；通过 new 表达式取得初始化后的实例。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $console->run(['grpc:serve']);
     */
    public function __construct(private \Loom\GrpcServer $server)
    {
    }

    /**
     * 启动 gRPC 服务并持续监听请求。
     *
     * 参数：无。
     *
     * @return int 成功返回 0；异常交给控制台转换为非零退出码。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $console->run(['grpc:serve']);
     */
    public function handle(): int
    {
        $this->server->run();
        return 0;
    }
}
