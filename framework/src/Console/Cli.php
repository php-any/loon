<?php

declare(strict_types=1);

namespace Loom;

// CLI 仅处理历史参数兼容；命令注册、执行和清理由 Kernel 管理。
/**
 * 兼容历史 command 前缀，将命令交给 ConsoleKernel 统一执行和清理。
 *
 * 构造参数：
 * - $kernel（\Loom\Console\ConsoleKernel）：负责命令注册、执行和作用域清理的控制台内核。
 *
 * 返回值：使用 new 构造时得到 Cli 实例；注册到容器时由容器管理实例的创建与复用。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $code = $cli->run(['command', 'list']);
 */
final class Cli
{
    /**
     * 初始化 Cli 实例及其依赖。兼容历史 command 前缀，将命令交给 ConsoleKernel 统一执行和清理。
     *
     * @param \Loom\Console\ConsoleKernel $kernel 负责命令注册、执行和作用域清理的控制台内核。
     * @return void 无直接返回值；通过 new 表达式取得初始化后的实例。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $code = $cli->run(['command', 'list']);
     */
    public function __construct(private \Loom\Console\ConsoleKernel $kernel)
    {
    }

    /**
     * 移除可选的 command 前缀，然后将参数交给控制台内核。
     *
     * @param array $args 不含可执行文件名的命令及参数列表，例如 ['queue:work', '--once']。
     * @return int 内核返回的命令退出码。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $code = $cli->run(['list']);
     */
    public function run(array $args): int
    {
        if (($args[0] ?? '') === 'command') {
            $args = array_slice($args, 1);
        }
        return $this->kernel->handle($args);
    }
}
