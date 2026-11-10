<?php

declare(strict_types=1);

namespace Loom\Monitoring;

/** 判断是否应对当前上下文开启 Monitoring 采集（关联 id 仍总会建立）。 */
final class MonitoringCollector
{
    public static function shouldCollect(MonitoringOptions $options, MonitoringContext $context): bool
    {
        if (!$options->collects()) {
            return false;
        }
        if ($context->channel === 'http') {
            $path = (string)($context->tags['path'] ?? '');
            foreach ($options->ignore_paths as $pattern) {
                if (self::pathMatches((string)$pattern, $path)) {
                    return false;
                }
            }
        }
        if ($context->channel === 'console') {
            $command = (string)($context->tags['command'] ?? '');
            foreach ($options->ignore_commands as $ignored) {
                if ($command === (string)$ignored) {
                    return false;
                }
            }
        }
        return true;
    }

    private static function pathMatches(string $pattern, string $path): bool
    {
        if ($pattern === $path) {
            return true;
        }
        if (str_contains($pattern, '*')) {
            $regex = '/^' . str_replace('\*', '.*', preg_quote($pattern, '/')) . '$/';
            return (bool)preg_match($regex, $path);
        }
        return false;
    }
}
