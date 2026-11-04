<?php

declare(strict_types=1);

namespace Loom\Console\Commands;

/**
 * migrate:up 命令。先加载 framework/bootstrap/database.php，由该脚本初始化 loom_base、loom 并向上执行配置里的迁移命名空间，再把该命名空间的状态输出成 JSON。
 *
 * 构造参数：
 * - $scope（\Loom\ServiceScope）：加载数据库引导和读取迁移配置的作用域。
 *
 * 返回值：尚未执行迁移的 MigrateUpCommand。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $console->run(['migrate:up']);
 */
final class MigrateUpCommand extends \Loom\Console\Command
{
    protected string $signature = 'migrate:up';
    protected string $description = '执行数据库迁移';

    /**
     * 保存服务作用域。不加载数据库引导，也不执行迁移。
     *
     * @param \Loom\ServiceScope $scope 要保存的服务作用域。
     * @return void 无返回值，作用域已保存在实例上。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $command = new \Loom\Console\Commands\MigrateUpCommand($scope);
     */
    public function __construct(private \Loom\ServiceScope $scope)
    {
    }

    /**
     * 取出配置和迁移器，加载 framework/bootstrap/database.php，再按 migrations 配置收集文件并把 status 写成 JSON，返回 0。引导脚本会初始化 loom_base 与 loom，并向上执行配置中的命名空间；这里打印的就是该命名空间的状态。引导或取状态失败时异常直接离开。
     *
     * 参数：无。
     *
     * @return int 写出状态后为 0。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $console->run(['migrate:up']);
     */
    public function handle(): int
    {
        $config = $this->scope->container()->get(\Loom\Config::class);
        $migrator = $this->scope->container()->get(\Loom\Migrations::class);
        $this->scope->load(dirname(__DIR__, 3) . '/bootstrap/database.php');
        $migrations = $config->get('migrations');
        $plan = $migrator->files($config->path($migrations['path']));
        $this->json($migrator->status($migrations['namespace'], $plan));
        return 0;
    }
}
