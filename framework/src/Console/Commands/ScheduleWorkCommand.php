<?php

declare(strict_types=1);

namespace Loom\Console\Commands;

/**
 * schedule:work 命令。先做运行时检查并加载数据库引导，再反复调用调度器。传入 --once 时只 tick 一次，不写心跳也不睡眠；否则每轮先心跳再 tick，然后 sleep(1)，循环不返回。
 *
 * 构造参数：
 * - $scope（\Loom\ServiceScope）：检查运行时、加载引导和取得 Schedule 的作用域。
 *
 * 返回值：尚未进入调度循环的 ScheduleWorkCommand。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $console->run(['schedule:work', '--once']);
 */
final class ScheduleWorkCommand extends \Loom\Console\Command
{
    protected string $signature = 'schedule:work {--once : 执行一次后退出}';
    protected string $description = '生成到期任务';

    /**
     * 保存服务作用域。不检查运行时，也不进入调度循环。
     *
     * @param \Loom\ServiceScope $scope 要保存的服务作用域。
     * @return void 无返回值，作用域已保存在实例上。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $command = new \Loom\Console\Commands\ScheduleWorkCommand($scope);
     */
    public function __construct(private \Loom\ServiceScope $scope)
    {
    }

    /**
     * 先做 Doctor 检查，再加载 framework/bootstrap/database.php。选项 once 为真时跳过心跳和 sleep，只调用一次 Schedule::tick 并返回 0。否则每轮先写名称为 scheduler、间隔 1 秒的心跳，再 tick，然后 sleep(1)，一直循环。检查、迁移或 tick 抛出的异常直接离开，不会返回 0。
     *
     * 参数：无。
     *
     * @return int 仅在 --once 时，一轮 tick 之后返回 0。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $console->run(['schedule:work', '--once']);
     */
    public function handle(): int
    {
        $services = $this->scope->container();
        $services->get(\Loom\Doctor::class)->check();
        $this->scope->load(dirname(__DIR__, 3) . '/bootstrap/database.php');
        $once = $this->option('once');
        $pulse = new \Loom\RuntimePulse($services->get(\Loom\Config::class), $services->get(\Loom\Database::class), 'scheduler', 1);
        $worker = $services->get(\Loom\Schedule::class);
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
