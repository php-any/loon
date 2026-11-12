<?php

declare(strict_types=1);

/**
 * Redis 命名连接。默认连接与历史 LOOM_REDIS_URL / LOOM_REDIS_PREFIX 行为一致。
 */
return [
    'default' => env('LOOM_REDIS_CONNECTION', 'default'),
    'connections' => [
        'default' => [
            'url' => env('LOOM_REDIS_URL', 'redis://127.0.0.1:6379/0'),
            'prefix' => env('LOOM_REDIS_PREFIX', 'loom:'),
        ],
    ],
];
