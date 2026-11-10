<?php

declare(strict_types=1);

/**
 * 监控会话收集：驱动、按宿主入口的通道过滤，以及会话内 record* 开关。
 * 关联 id（CorrelationId）不受本配置影响；日记写出目标见 config/diary.php。
 * 修改后需重启长期运行的进程。
 */
return [
    /**
     * log（默认）：收集会话，dispose 时经 Diary 写出 monitoring.session；null：不收集。
     */
    'driver' => env('LOOM_MONITORING_DRIVER', 'log'),

    /** 各宿主入口是否开启会话缓冲（ASP.NET 类别过滤风格）。 */
    'channels' => [
        'http' => true,
        'console' => true,
        'timer' => true,
        'queue' => true,
        'schedule' => true,
        'job' => true,
        'tcp' => true,
        'grpc' => true,
    ],

    /** 在已 active 的会话内，哪些 record* 写入 entries。 */
    'record' => [
        'sql' => true,
        'http' => true,
        'console' => true,
        'events' => true,
    ],
];
