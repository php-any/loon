<?php

declare(strict_types=1);

/**
 * 同步缓存存储。节缺失时 CacheOptions 使用内置默认（redis + array）。
 */
return [
    'default' => env('LOOM_CACHE_STORE', 'redis'),
    'stores' => [
        'redis' => ['driver' => 'redis'],
        'array' => ['driver' => 'array'],
    ],
];
