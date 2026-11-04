<?php

declare(strict_types=1);

namespace Loom\Console\Commands;

/**
 * migrate:down 命令。按配置收集迁移文件，回退该命名空间的最后一批，再把状态输出成 JSON。不加载 database.php，因此不会先做向上迁移。
 *
 * 构造参数：
 * - $scope（\Loom\ServiceScope）：读取配置和迁移器的作用域。
 *
 * 返回值：尚未回退迁移的 MigrateDownCommand。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $console->run(['migrate:down']);
 */
final class MigrateDownCommand extends \Loom\Console\Command
{
    protected string $signature = 'migrate:down';
    protected string $description = '撤销应用最后一个迁移批次';

    /**
     * 保存服务作用域。不读取配置，也不回退迁移。
     *
     * @param \Loom\ServiceScope $scope 要保存的服务作用域。
     * @return void 无返回值，作用域已保存在实例上。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $command = new \Loom\Console\Commands\MigrateDownCommand($scope);
     */
    public function __construct(private \Loom\ServiceScope $scope)
    {
    }

    /**
     * 从作用域取出配置和迁移器，按 migrations.path 收集文件，以向下方向执行 run，再把 status 写成 JSON，返回 0。配置缺失、收集文件失败或回退失败时异常直接离开，不写成功状态。
     *
     * 参数：无。
     *
     * @return int 回退并写出状态后为 0。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $console->run(['migrate:down']);
     */
    public function handle(): int
    {
        $config = $this->scope->container()->get(\Loom\Config::class);
        $migrator = $this->scope->container()->get(\Loom\Migrations::class);
        $migrations = $config->get('migrations');
        $plan = $migrator->files($config->path($migrations['path']));
        $migrator->run($migrations['namespace'], $plan, true);
        $this->json($migrator->status($migrations['namespace'], $plan));
        return 0;
    }
}
