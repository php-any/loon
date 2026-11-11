<?php

declare(strict_types=1);

namespace Loom\Monitoring;

/**
 * 当前执行回调栈上的 Monitoring，供单例服务（如 Filesystem）在操作时点记录事件。
 */
final class ActiveMonitoring
{
    /** @var list<?Monitoring> */
    private static array $stack = [];

    public static function push(?Monitoring $monitoring): void
    {
        self::$stack[] = $monitoring;
    }

    public static function pop(): void
    {
        if (self::$stack !== []) {
            array_pop(self::$stack);
        }
    }

    public static function current(): ?Monitoring
    {
        if (self::$stack === []) {
            return null;
        }
        return self::$stack[count(self::$stack) - 1];
    }
}
