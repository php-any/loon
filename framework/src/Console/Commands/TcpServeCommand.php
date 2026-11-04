<?php

declare(strict_types=1);

namespace Loom\Console\Commands;

/**
 * tcp:serve 命令。handle 调用已保存的 TCP 服务并阻塞到它返回，成功时退出码为 0。本命令不先做运行时检查。
 *
 * 构造参数：
 * - $server（\Loom\TcpServer）：要启动的 TCP 服务。
 *
 * 返回值：尚未监听的 TcpServeCommand。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $console->run(['tcp:serve']);
 */
final class TcpServeCommand extends \Loom\Console\Command
{
    protected string $signature = 'tcp:serve';
    protected string $description = '启动 TCP JSON 行协议服务';

    /**
     * 保存 TCP 服务。不调用 run，也不绑定端口。
     *
     * @param \Loom\TcpServer $server 要保存的 TCP 服务。
     * @return void 无返回值，服务已保存在实例上。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $command = new \Loom\Console\Commands\TcpServeCommand($server);
     */
    public function __construct(private \Loom\TcpServer $server)
    {
    }

    /**
     * 调用保存的 TCP 服务的 run。run 返回之后才返回 0。监听或协议错误由服务器抛出，本方法不接住。
     *
     * 参数：无。
     *
     * @return int run 正常返回时为 0。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $console->run(['tcp:serve']);
     */
    public function handle(): int
    {
        $this->server->run();
        return 0;
    }
}
