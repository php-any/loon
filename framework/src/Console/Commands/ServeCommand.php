<?php

declare(strict_types=1);

namespace Loom\Console\Commands;

/**
 * serve 命令。先做运行时检查，再调用已保存的 HTTP 服务。检查失败时不会启动服务。run 返回之后退出码才是 0。
 *
 * 构造参数：
 * - $server（\Loom\Server）：要启动的 HTTP 服务。
 * - $doctor（\Loom\Doctor）：启动前要执行的运行时检查。
 *
 * 返回值：尚未监听的 ServeCommand。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $console->run(['serve']);
 */
final class ServeCommand extends \Loom\Console\Command
{
    protected string $signature = 'serve';
    protected string $description = '启动 HTTP 与 WebSocket 服务';

    /**
     * 保存 HTTP 服务和运行时检查。不调用 check，也不调用 run。
     *
     * @param \Loom\Server $server 要保存的 HTTP 服务。
     * @param \Loom\Doctor $doctor 要保存的运行时检查。
     * @return void 无返回值，两个依赖已保存在实例上。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $command = new \Loom\Console\Commands\ServeCommand($server, $doctor);
     */
    public function __construct(private \Loom\Server $server, private \Loom\Doctor $doctor)
    {
    }

    /**
     * 先调用 Doctor::check，通过后再调用 HTTP 服务的 run。run 返回之后才返回 0。检查失败时不会启动服务；run 抛出的异常本方法不接住。
     *
     * 参数：无。
     *
     * @return int 服务 run 正常返回时为 0。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $console->run(['serve']);
     */
    public function handle(): int
    {
        $this->doctor->check();
        $this->server->run();
        return 0;
    }
}
