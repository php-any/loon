<?php

declare(strict_types=1);

/**
 * 应用日记：写入目标与序列化格式。RequestLog、LoggingMonitoring 与业务侧 Loom\Logging\Diary 共用本配置；Monitoring 缓冲观测事件，收尾时仍经 Diary 写出。
 * 修改后需重启长期运行的进程。
 */
return [
    /**
     * 写入器：stderr、file、null、stack，或通过 diary.extend 登记的驱动名。
     */
    'writer' => getenv('LOOM_DIARY_WRITER') ?: 'stack',

    /**
     * writer 为 stack 时的子写入器；每项可含 driver、formatter（覆盖全局 file 等）。
     *
     * @var list<array<string, mixed>>
     */
    'stack' => [
        ['driver' => 'stderr', 'formatter' => 'text'],
        ['driver' => 'file', 'formatter' => 'json'],
    ],

    /**
     * 全局格式化器：json（默认，JSON 行）或 text（纯文本）；stack 子项未指定时使用此值。
     */
    'formatter' => getenv('LOOM_DIARY_FORMATTER') ?: 'json',

    /**
     * writer 为 file 或 stack 中 file 子项未指定 file 时的路径。空则使用 storage/logs/diary.log。
     */
    'file' => getenv('LOOM_DIARY_FILE') ?: '',

    /**
     * 最低日记级别；空表示不过滤。条目仍携带 level 字段。
     */
    'level' => getenv('LOOM_DIARY_LEVEL') ?: '',
];
