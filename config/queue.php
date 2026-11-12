<?php

declare(strict_types=1);

/**
 * 队列连接与名称。内置驱动为 Redis Stream；自定义驱动通过 QueueBuilder::extend() 登记。
 */
return [
    'default' => env('LOOM_QUEUE_CONNECTION', 'default'),
    'connections' => [
        'default' => [
            'driver' => 'redis',
            'connection' => env('LOOM_QUEUE_REDIS', env('LOOM_REDIS_CONNECTION', 'default')),
            'name' => env('LOOM_QUEUE_NAME', ''),
        ],
    ],
];
