<?php

declare(strict_types=1);

namespace Loom\Console\Commands;

/**
 * migrate:status 命令。按配置收集迁移文件，只把各版本是否已应用写成 JSON，不执行向上或向下迁移。
 *
 * 构造参数：
 * - $scope（\Loom\ServiceScope）：读取配置和迁移器的作用域。
 *
 * 返回值：尚未读取状态的 MigrateStatusCommand。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $console->run(['migrate:status']);
 */
final class MigrateStatusCommand extends \Loom\Console\Command
{
    protected string $signature = 'migrate:status';
    protected string $description = '查看迁移状态';

    /**
     * 保存服务作用域。不读取迁移状态。
     *
     * @param \Loom\ServiceScope $scope 要保存的服务作用域。
     * @return void 无返回值，作用域已保存在实例上。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $command = new \Loom\Console\Commands\MigrateStatusCommand($scope);
     */
    public function __construct(private \Loom\ServiceScope $scope)
    {
    }

    /**
     * 从作用域取出配置和迁移器，按 migrations.path 收集文件，把 status 写成 JSON 并返回 0。不调用 run。配置、文件或状态查询失败时异常直接离开。
     *
     * 参数：无。
     *
     * @return int 写出状态后为 0。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $console->run(['migrate:status']);
     */
    public function handle(): int
    {
        $config = $this->scope->container()->get(\Loom\Config::class);
        $migrator = $this->scope->container()->get(\Loom\Migrations::class);
        $migrations = $config->get('migrations');
        $plan = $migrator->files($config->path($migrations['path']));
        $this->json($migrator->status($migrations['namespace'], $plan));
        return 0;
    }
}
