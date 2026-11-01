<?php

declare(strict_types=1);

namespace Loom\Console\Commands;

/**
 * 回退最近批次数据库迁移并输出迁移状态。由控制台容器解析依赖并调用。
 *
 * 构造参数：
 * - $scope（\Loom\ServiceScope）：命令运行期间的服务作用域，提供配置、数据库和业务服务。
 *
 * 返回值：使用 new 构造时得到 MigrateDownCommand 实例；注册到容器时由容器管理实例的创建与复用。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $console->run(['migrate:down']);
 */
final class MigrateDownCommand extends \Loom\Console\Command
{
    protected string $signature = 'migrate:down';
    protected string $description = '撤销应用最后一个迁移批次';

    /**
     * 初始化 MigrateDownCommand 实例及其依赖。回退最近批次数据库迁移并输出迁移状态。由控制台容器解析依赖并调用。
     *
     * @param \Loom\ServiceScope $scope 命令运行期间的服务作用域，提供配置、数据库和业务服务。
     * @return void 无直接返回值；通过 new 表达式取得初始化后的实例。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $console->run(['migrate:down']);
     */
    public function __construct(private \Loom\ServiceScope $scope)
    {
    }

    /**
     * 回退最近批次数据库迁移并输出迁移状态。
     *
     * 参数：无。
     *
     * @return int 成功返回 0；异常交给控制台转换为非零退出码。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $console->run(['migrate:down']);
     */
    public function handle(): int
    {
        $config = $this->scope->services()->get(\Loom\Config::class);
        $migrator = $this->scope->services()->get(\Loom\Migrations::class);
        $migrations = $config->get('migrations');
        $plan = $migrator->files($config->path($migrations['path']));
        $migrator->run($migrations['namespace'], $plan, true);
        $this->json($migrator->status($migrations['namespace'], $plan));
        return 0;
    }
}
