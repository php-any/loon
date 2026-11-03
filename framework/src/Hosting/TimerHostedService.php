<?php

declare(strict_types=1);

namespace Loom;

/**
 * 把定时器运行器接进宿主生命周期。HTTP 服务用它在监听前启动定时器，退出时停止。
 *
 * 构造参数：
 * - $timers（TimerRunner）：已经登记定时器、尚未启动的运行器。
 *
 * 返回值：使用 new 构造时得到 TimerHostedService 实例。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $host->service(new TimerHostedService($timers));
 */
final class TimerHostedService implements HostedService
{
    /**
     * 保存定时器运行器。
     *
     * @param TimerRunner $timers 模块注册器创建的运行器。
     * @return void 无直接返回值。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $service = new TimerHostedService($runner);
     */
    public function __construct(private TimerRunner $timers)
    {
    }

    /**
     * 启动全部已登记定时器。重复启动由运行器忽略。
     *
     * 参数：无。
     *
     * @return void 无返回值。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $service->start();
     */
    public function start(): void
    {
        $this->timers->start();
    }

    /**
     * 停止后续节拍。正在执行的那一次定时器会跑完。
     *
     * 参数：无。
     *
     * @return void 无返回值。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $service->stop();
     */
    public function stop(): void
    {
        $this->timers->stop();
    }
}
