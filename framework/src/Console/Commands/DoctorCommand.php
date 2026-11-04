<?php

declare(strict_types=1);

namespace Loom\Console\Commands;

/**
 * doctor 命令。调用已保存的 Doctor 做运行时检查，通过后写一行 Origami std: OK 并返回 0。检查失败时不会写这一行。
 *
 * 构造参数：
 * - $doctor（\Loom\Doctor）：要执行的运行时检查。
 *
 * 返回值：尚未开始检查的 DoctorCommand。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $console->run(['doctor']);
 */
final class DoctorCommand extends \Loom\Console\Command
{
    protected string $signature = 'doctor';
    protected string $description = '验证 Origami 标准库能力';

    /**
     * 保存运行时检查服务。不调用 check。
     *
     * @param \Loom\Doctor $doctor 要保存的 Doctor。
     * @return void 无返回值，检查服务已保存在实例上。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $command = new \Loom\Console\Commands\DoctorCommand($doctor);
     */
    public function __construct(private \Loom\Doctor $doctor)
    {
    }

    /**
     * 调用保存的 Doctor::check。通过后写一行 Origami std: OK 并返回 0。check 抛出时这一行不会写出，异常交给控制台。
     *
     * 参数：无。
     *
     * @return int 检查通过后为 0。
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
