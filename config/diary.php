<?php

declare(strict_types=1);

/**
 * 应用日记：写入目标与序列化格式。RequestLog、LoggingMonitoring 与业务侧 Loom\Logging\Diary 共用本配置；Monitoring 缓冲观测事件，收尾时仍经 Diary 写出。
 * 修改后需重启长期运行的进程。
 */
return [
    'writer' => getenv('LOOM_DIARY_WRITER') ?: 'stack',
    'stack' => [
        ['driver' => 'stderr', 'formatter' => 'text'],
        ['driver' => 'file', 'formatter' => 'json'],
    ],
    'formatter' => getenv('LOOM_DIARY_FORMATTER') ?: 'json',
    'file' => getenv('LOOM_DIARY_FILE') ?: '',
    'level' => getenv('LOOM_DIARY_LEVEL') ?: '',
];
