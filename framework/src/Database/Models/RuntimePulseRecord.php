<?php

declare(strict_types=1);

namespace Loom\Models;

/**
 * loom_runtime_pulses 的一行。按进程名保存启动时间、最近一次心跳和节拍数。new 出来的对象不会自己写库。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $pulse = new RuntimePulseRecord();
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
