<?php

declare(strict_types=1);

namespace Loom\Monitoring;

/** recordEvent 名称到 watcher 桶的映射。 */
final class MonitoringEventBuckets
{
    public static function bucket(string $name): ?string
    {
        if (str_starts_with($name, 'queue.') || str_starts_with($name, 'job.')) {
            return 'queue';
        }
        if (str_starts_with($name, 'schedule.')) {
            return 'schedule';
        }
        if (str_starts_with($name, 'tcp.')) {
            return 'tcp';
        }
        if (str_starts_with($name, 'grpc.')) {
            return 'grpc';
        }
        if (str_starts_with($name, 'filesystem.')) {
            return 'filesystem';
        }
        return null;
    }
}
