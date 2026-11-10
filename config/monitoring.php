<?php

declare(strict_types=1);

/**
 * 可观测性采集与仪表盘。日记仍由 config/diary.php 驱动；driver=log 时 monitoring.session 只经 Diary 写出。
 */
return [
    'enabled' => env('LOOM_MONITORING_ENABLED', true),
    'driver' => env('LOOM_MONITORING_DRIVER', 'log'),
    'ignore_paths' => [],
    'ignore_commands' => [],
    'watchers' => [
        'sql' => ['enabled' => env('LOOM_MONITORING_SQL', true), 'slow' => 0],
        'http' => ['enabled' => true, 'ignore_methods' => [], 'ignore_status' => []],
        'console' => ['enabled' => true],
        'queue' => ['enabled' => true],
        'schedule' => ['enabled' => true],
        'events' => ['enabled' => true],
        'tcp' => ['enabled' => true],
        'grpc' => ['enabled' => true],
    ],
    'dashboard' => [
        'enabled' => env('LOOM_MONITORING_DASHBOARD', false),
        'path' => 'monitoring',
    ],
    'storage' => [
        'connection' => null,
        'retain_hours' => 24,
    ],
];
