<?php

declare(strict_types=1);

namespace Loom\Console;

/**
 * 在一次服务作用域里装好控制台并执行一条命令，结束时无论成败都关闭该作用域。
 *
 * 构造参数：
 * - $scope（\Loom\ServiceScope）：这次命令独占的作用域，由 handle 在结束时关闭。
 * - $output（Output）：交给内部 Console 的输出，作用域外的异常也写到这里。
 *
 * 返回值：尚未装入命令的 ConsoleKernel。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $kernel = new \Loom\Console\ConsoleKernel($scope, $output); $code = $kernel->handle(['list']);
 */
final class ConsoleKernel
{
    private ?Console $console = null;

    /**
     * 保存这次命令的服务作用域和输出。不登记命令，也不关闭作用域。
     *
     * @param \Loom\ServiceScope $scope 要保存的服务作用域。
     * @param Output $output 要保存的输出。
     * @return void 无返回值，作用域和输出已收进实例。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $kernel = new \Loom\Console\ConsoleKernel($scope, $output);
     */
    public function __construct(private \Loom\ServiceScope $scope, private Output $output)
    {
    }

    /**
     * 第一次调用时用作用域里的容器创建 Console，登记内置命令，加载 framework/bootstrap/commands.php，再登记模块命令。容器里若有 CommandRegistrar，就再调用它一次；取出来的对象不是该接口时抛出 LogicException。之后一直返回同一个 Console，不会重复登记。
     *
     * 参数：无。
     *
     * @return Console 本作用域内复用的控制台。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $console = $kernel->console();
     */
    public function console(): Console
    {
        if ($this->console !== null) {
            return $this->console;
        }
        $services = $this->scope->container();
        $modules = $services->get(\Loom\ModuleRegistry::class)->configured();
        $modules->services($services);
        $console = new Console($this->output, $services);
        foreach ([
            Commands\DoctorCommand::class,
            Commands\ServeCommand::class,
            Commands\TcpServeCommand::class,
            Commands\GrpcServeCommand::class,
            Commands\MigrateUpCommand::class,
            Commands\MigrateDownCommand::class,
            Commands\MigrateStatusCommand::class,
            Commands\QueueWorkCommand::class,
            Commands\ScheduleWorkCommand::class,
            Commands\JobCommand::class,
        ] as $class) {
            $console->add($class);
        }
        require dirname(__DIR__, 2) . '/bootstrap/commands.php';
        $modules->commands($console);
        if ($services->has(CommandRegistrar::class)) {
            $registrar = $services->get(CommandRegistrar::class);
            if (!($registrar instanceof CommandRegistrar)) {
                throw new \LogicException('Command registrar must implement CommandRegistrar');
            }
            $registrar->register($console);
        }
        $this->console = $console;
        return $console;
    }

    /**
     * 取出控制台并执行 run。run 没有接住的异常写到输出并返回 1。成功、失败或写错误之后，finally 都会关闭构造时保存的作用域。
     *
     * @param array $args 已去掉解释器和脚本名的参数，第一项是命令名。
     * @return int 命令退出码；控制台之外冒出来的异常为 1。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $code = $kernel->handle(['list']);
     */
    public function handle(array $args): int
    {
        try {
            return $this->console()->run($args);
        } catch (\Throwable $error) {
            $this->output->error($error->getMessage());
            return 1;
        } finally {
            $this->scope->close();
        }
    }
}
