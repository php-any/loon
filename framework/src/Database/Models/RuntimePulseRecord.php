<?php

declare(strict_types=1);

namespace Loom\Models;

/**
 * 按进程名称保存启动时间、最近心跳和节拍计数，映射到 loom_runtime_pulses；继承 Model 的查询方法，实例本身不自动持久化。
 *
 * 构造参数：本声明未定义构造参数；继承类型沿用父类的构造约定。
 *
 * 返回值：使用 new 构造时得到 RuntimePulseRecord 实例；注册到容器时由容器管理实例的创建与复用。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $rows = \Loom\Models\RuntimePulseRecord::using($database)->limit(10)->rows();
 */
#[\Database\Annotation\Table('loom_runtime_pulses')]
final class RuntimePulseRecord extends \Loom\Model
{
    #[\Database\Annotation\Id]
    #[\Database\Annotation\Column('name', false)]
    public string $name;

    #[\Database\Annotation\Column('started', false)]
    public int $started;

    #[\Database\Annotation\Column('seen', false)]
    public int $seen;

    #[\Database\Annotation\Column('ticks', false)]
    public int $ticks;

    #[\Database\Annotation\Column('interval_seconds', false)]
    public int $interval_seconds;
}
