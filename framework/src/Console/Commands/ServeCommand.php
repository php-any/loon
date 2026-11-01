<?php

declare(strict_types=1);

namespace Loom\Console\Commands;

/**
 * 检查运行时依赖后启动 HTTP 服务。由控制台容器解析依赖并调用。
 *
 * 构造参数：
 * - $server（\Loom\Server）：对应协议的服务器实例，run 会进入监听循环。
 * - $doctor（\Loom\Doctor）：运行时依赖检查服务。
 *
 * 返回值：使用 new 构造时得到 ServeCommand 实例；注册到容器时由容器管理实例的创建与复用。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $console->run(['serve']);
 */
final class ServeCommand extends \Loom\Console\Command
{
    protected string $signature = 'serve';
    protected string $description = '启动 HTTP 与 WebSocket 服务';

    /**
     * 初始化 ServeCommand 实例及其依赖。检查运行时依赖后启动 HTTP 服务。由控制台容器解析依赖并调用。
     *
     * @param \Loom\Server $server 对应协议的服务器实例，run 会进入监听循环。
     * @param \Loom\Doctor $doctor 运行时依赖检查服务。
     * @return void 无直接返回值；通过 new 表达式取得初始化后的实例。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $console->run(['serve']);
     */
    public function __construct(private \Loom\Server $server, private \Loom\Doctor $doctor)
    {
    }

    /**
     * 检查运行时依赖后启动 HTTP 服务。
     *
     * 参数：无。
     *
     * @return int 成功返回 0；异常交给控制台转换为非零退出码。
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
