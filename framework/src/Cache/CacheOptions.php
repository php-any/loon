<?php

declare(strict_types=1);

namespace Loom;

/**
 * 缓存存储选项，对应 config/cache.php。节缺失时保留类默认值。
 */
final class CacheOptions
{
    public string $default = 'redis';

    /**
     * @var array<string, array<string, mixed>>
     */
    public array $stores = [
        'redis' => ['driver' => 'redis'],
        'array' => ['driver' => 'array'],
    ];
}
