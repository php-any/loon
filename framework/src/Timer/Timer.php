<?php

declare(strict_types=1);

namespace Loom;

/**
 * 为无参可调用类声明周期秒数，合法性在 TimerRunner 注册时检查，最小为一秒。
 *
 * 构造参数：
 * - $seconds（int）：执行周期，单位秒；注册时要求至少为 1。
 *
 * 返回值：使用 new 构造时得到 Timer 实例；注册到容器时由容器管理实例的创建与复用。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example #[\Loom\Timer(5)] final class Heartbeat { public function __invoke(): void {} }
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class Timer
{
    public int $seconds;
    /**
     * 初始化 Timer 实例及其依赖。为无参可调用类声明周期秒数，合法性在 TimerRunner 注册时检查，最小为一秒。
     *
     * @param int $seconds 执行周期，单位秒；注册时要求至少为 1。
     * @return void 无直接返回值；通过 new 表达式取得初始化后的实例。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example #[\Loom\Timer(5)] final class Heartbeat { public function __invoke(): void {} }
     */
    public function __construct(int $seconds)
    {
        $this->seconds = $seconds;
    }
}
