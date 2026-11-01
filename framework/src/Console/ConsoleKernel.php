<?php

declare(strict_types=1);

namespace Loom\Console;

/**
 * 在独立服务作用域中注册内置、模块及应用命令，统一处理退出码并关闭作用域。
 *
 * 构造参数：
 * - $scope（\Loom\ServiceScope）：本次命令拥有的服务作用域，handle 结束时关闭。
 * - $output（Output）：命令的标准输出及错误输出适配器。
 *
 * 返回值：使用 new 构造时得到 ConsoleKernel 实例；注册到容器时由容器管理实例的创建与复用。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $code = $kernel->handle(['list']);
 */
final class ConsoleKernel
{
    private ?Console $console = null;

    /**
     * 初始化 ConsoleKernel 实例及其依赖。在独立服务作用域中注册内置、模块及应用命令，统一处理退出码并关闭作用域。
     *
     * @param \Loom\ServiceScope $scope 本次命令拥有的服务作用域，handle 结束时关闭。
     * @param Output $output 命令的标准输出及错误输出适配器。
     * @return void 无直接返回值；通过 new 表达式取得初始化后的实例。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $code = $kernel->handle(['list']);
     */
    public function __construct(private \Loom\ServiceScope $scope, private Output $output)
    {
    }

    /**
     * 延迟构建并缓存命令注册表，加载内置、模块命令并调用已绑定的 CommandRegistrar。
     *
     * 参数：无。
     *
     * @return Console 当前作用域复用的 Console。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $console = $kernel->console();
     */
    public function console(): Console
    {
        if ($this->console !== null) {
            return $this->console;
        }
        $services = $this->scope->services();
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
     * 运行命令并将异常转换为退出码，最终关闭服务作用域。
     *
     * @param array $args 去除解释器和脚本路径后的参数列表，例如 ['help', 'serve']。
     * @return int 命令退出码；未处理异常返回 1。
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
