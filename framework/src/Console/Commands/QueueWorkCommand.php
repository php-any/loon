<?php

declare(strict_types=1);

namespace Loom\Console\Commands;

/**
 * 初始化数据库并运行队列工作循环，--once 仅执行一次。由控制台容器解析依赖并调用。
 *
 * 构造参数：
 * - $scope（\Loom\ServiceScope）：命令运行期间的服务作用域，提供配置、数据库和业务服务。
 *
 * 返回值：使用 new 构造时得到 QueueWorkCommand 实例；注册到容器时由容器管理实例的创建与复用。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $console->run(['queue:work', '--once']);
 */
final class QueueWorkCommand extends \Loom\Console\Command
{
    protected string $signature = 'queue:work {--once : 执行一次后退出}';
    protected string $description = '消费队列';

    /**
     * 初始化 QueueWorkCommand 实例及其依赖。初始化数据库并运行队列工作循环，--once 仅执行一次。由控制台容器解析依赖并调用。
     *
     * @param \Loom\ServiceScope $scope 命令运行期间的服务作用域，提供配置、数据库和业务服务。
     * @return void 无直接返回值；通过 new 表达式取得初始化后的实例。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $console->run(['queue:work', '--once']);
     */
    public function __construct(private \Loom\ServiceScope $scope)
    {
    }

    /**
     * 初始化数据库并运行队列工作循环，--once 仅执行一次。
     *
     * 参数：无。
     *
     * @return int 成功返回 0；异常交给控制台转换为非零退出码。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $console->run(['queue:work', '--once']);
     */
    public function handle(): int
    {
        $services = $this->scope->services();
        $services->get(\Loom\Doctor::class)->check();
        $this->scope->load(dirname(__DIR__, 3) . '/bootstrap/database.php');
        $once = $this->option('once');
        $pulse = new \Loom\RuntimePulse($services->get(\Loom\Config::class), $services->get(\Loom\Database::class), 'queue', 1);
        $worker = $services->get(\Loom\Queue::class);
        do {
            if (!$once) {
                $pulse->beat();
            }
            $worker->tick();
            if (!$once) {
                sleep(1);
            }
        } while (!$once);
        return 0;
    }
}
