<?php

declare(strict_types=1);

namespace Loom\Filesystem;

use Loom\Monitoring\ActiveMonitoring;

/**
 * 在活跃 Monitoring 会话中记录 filesystem.* 事件（不含文件正文）。
 */
final class FilesystemMonitor
{
    /**
     * @param array<string, scalar|null> $data
     */
    public static function record(string $event, array $data): void
    {
        $monitoring = ActiveMonitoring::current();
        if ($monitoring === null || !$monitoring->isActive()) {
            return;
        }
        $monitoring->recordEvent($event, $data);
    }

    public static function write(string $disk, string $key, int $size): void
    {
        self::record('filesystem.write', ['disk' => $disk, 'key' => $key, 'size' => $size]);
    }

    public static function read(string $disk, string $key, int $size): void
    {
        self::record('filesystem.read', ['disk' => $disk, 'key' => $key, 'size' => $size]);
    }

    public static function delete(string $disk, string $key): void
    {
        self::record('filesystem.delete', ['disk' => $disk, 'key' => $key]);
    }

    public static function upload(string $disk, string $field, string $key, int $size): void
    {
        self::record('filesystem.upload', ['disk' => $disk, 'field' => $field, 'key' => $key, 'size' => $size]);
    }

    public static function serve(string $disk, string $key, int $size): void
    {
        self::record('filesystem.serve', ['disk' => $disk, 'key' => $key, 'size' => $size]);
    }
}
