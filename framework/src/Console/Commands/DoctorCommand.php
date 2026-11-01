<?php

declare(strict_types=1);

namespace Loom\Console\Commands;

/**
 * 执行运行时依赖检查，成功后打印检查结果。由控制台容器解析依赖并调用。
 *
 * 构造参数：
 * - $doctor（\Loom\Doctor）：运行时依赖检查服务。
 *
 * 返回值：使用 new 构造时得到 DoctorCommand 实例；注册到容器时由容器管理实例的创建与复用。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $console->run(['doctor']);
 */
final class DoctorCommand extends \Loom\Console\Command
{
    protected string $signature = 'doctor';
    protected string $description = '验证 Origami 标准库能力';

    /**
     * 初始化 DoctorCommand 实例及其依赖。执行运行时依赖检查，成功后打印检查结果。由控制台容器解析依赖并调用。
     *
     * @param \Loom\Doctor $doctor 运行时依赖检查服务。
     * @return void 无直接返回值；通过 new 表达式取得初始化后的实例。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $console->run(['doctor']);
     */
    public function __construct(private \Loom\Doctor $doctor)
    {
    }

    /**
     * 执行运行时依赖检查，成功后打印检查结果。
     *
     * 参数：无。
     *
     * @return int 成功返回 0；异常交给控制台转换为非零退出码。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $console->run(['doctor']);
     */
    public function handle(): int
    {
        $this->doctor->check();
        $this->info('Origami std: OK');
        return 0;
    }
}
