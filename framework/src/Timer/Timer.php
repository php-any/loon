<?php

declare(strict_types=1);

namespace Loom;

/**
 * 标在类上的周期秒数。本类只保存数字，是否至少一秒由 TimerRunner 注册时检查。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $timer = new Timer(5);
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class Timer
{
    public int $seconds;
    /**
     * 把周期秒数存进公开属性 $seconds。这里不检查是否大于 0。
     *
     * @param int $seconds 要保存的周期，单位秒。
     * @return void 不返回值。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $timer = new Timer(5);
     */
    public function __construct(int $seconds)
    {
        $this->seconds = $seconds;
    }
}
