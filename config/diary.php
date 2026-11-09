<?php

declare(strict_types=1);

/**
 * 应用日记：写入目标与序列化格式。RequestLog、LoggingMonitoring 与业务侧 Loom\Logging\Diary 共用本配置；Monitoring 缓冲观测事件，收尾时仍经 Diary 写出。
 * 修改后需重启长期运行的进程。
 */
return [
    /**
     * 写入器：stderr（默认）、file 或 null（丢弃）。
     */
    'writer' => getenv('LOOM_DIARY_WRITER') ?: 'stderr',

    /**
     * 格式化器：json（默认，JSON 行）或 text（纯文本）。
     */
    'formatter' => getenv('LOOM_DIARY_FORMATTER') ?: 'json',

    /**
     * writer 为 file 时的路径。空则使用 storage/logs/diary.log。
     */
    'file' => getenv('LOOM_DIARY_FILE') ?: '',
];
