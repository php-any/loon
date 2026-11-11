<?php

declare(strict_types=1);

namespace Loom\Filesystem;

/**
 * 文件存储选项，对应 config/filesystems.php。节缺失时保留类上默认值。
 */
final class FilesystemOptions
{
    public string $default = 'local';

    /** @var array<string, array<string, mixed>> */
    public array $disks = [
        'local' => [
            'driver' => 'local',
            'root' => 'storage/app',
        ],
        'public' => [
            'driver' => 'local',
            'root' => 'storage/app/public',
            'url' => '/files',
            'serve' => true,
        ],
    ];
}
