<?php

declare(strict_types=1);

namespace Loom;

/**
 * 标在类上的固定秒间隔或五段 Cron。秒间隔与 cron 互斥，至少配置一种；秒间隔须 ≥1。
 * Cron 使用与 {@see Cron} 相同的「分 时 日 月 周」五字段；时区默认为 UTC（IANA 名称）。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example 秒间隔在类上写 Timer 构造第一个参数，如每 5 秒。
 * @example Cron 写 Timer(0, 五段表达式, IANA 时区)，第一个参数必须为 0。
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class Timer
{
    public int $seconds = 0;
    public string $cron = '';
    public string $timezone = 'UTC';

    /**
     * @param int $seconds 固定周期秒数；与 $cron 同时指定时注册失败。
     * @param string $cron 五段 Cron 表达式；非空时忽略 $seconds。
     * @param string $timezone Cron 使用的 IANA 时区；仅 cron 模式有效。
     */
    public function __construct(int $seconds = 0, string $cron = '', string $timezone = 'UTC')
    {
        $this->seconds = $seconds;
        $this->cron = trim($cron);
        $this->timezone = trim($timezone);
        if ($this->timezone === '') {
            $this->timezone = 'UTC';
        }
    }

    public function isInterval(): bool
    {
        return $this->cron === '';
    }

    public function isCron(): bool
    {
        return $this->cron !== '';
    }

    /**
     * 供 schedule:list 与运维列表展示的人类可读调度说明。
     */
    public function scheduleLabel(): string
    {
        if ($this->isCron()) {
            return 'cron ' . $this->cron . ' (' . $this->timezone . ')';
        }

        return '每 ' . $this->seconds . ' 秒';
    }
}
