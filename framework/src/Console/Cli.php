<?php

declare(strict_types=1);

namespace Loom;

// CLI 仅处理历史参数兼容；命令注册、执行和清理由 Kernel 管理。
/**
 * 去掉历史调用里可选的 command 前缀，再把剩下的参数交给 ConsoleKernel 执行并清理作用域。
 *
 * 构造参数：
 * - $kernel（\Loom\Console\ConsoleKernel）：负责登记命令、执行并关闭作用域的内核。
 *
 * 返回值：只转发参数的 Cli。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $cli = new \Loom\Cli($kernel);
 */
final class Cli
{
    /**
     * 保存控制台内核。不改参数，也不运行命令。
     *
     * @param \Loom\Console\ConsoleKernel $kernel 之后 run 要委托的内核。
     * @return void 无返回值，内核已保存。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $cli = new \Loom\Cli($kernel);
     */
    public function __construct(private \Loom\Console\ConsoleKernel $kernel)
    {
    }

    /**
     * 第一项若是 command 就丢掉它，再把剩余列表交给内核 handle。列表为空，或第一项不是 command 时，原样转发。返回值就是内核的退出码。
     *
     * @param array $args 不含可执行文件名的参数；第一项可以是历史前缀 command。
     * @return int ConsoleKernel::handle 返回的退出码。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $code = $cli->run(['command', 'list']);
     */
    public function run(array $args): int
    {
        if (($args[0] ?? '') === 'command') {
            $args = array_slice($args, 1);
        }
        return $this->kernel->handle($args);
    }
}
