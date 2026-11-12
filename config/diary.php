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
     * writer 为 stack 时的子写入器；每项可含 driver、formatter、namer、pattern、file、date、timezone。
     *
     * @var list<array<string, mixed>>
     */
    'stack' => [
        ['driver' => 'stderr', 'formatter' => 'text'],
        ['driver' => 'file', 'formatter' => 'json'],
    ],

    /**
     * 全局格式化器：json、text 或 pattern；stack 子项未指定时使用此值。
     */
    'formatter' => getenv('LOOM_DIARY_FORMATTER') ?: 'json',

    /**
     * pattern 格式化器默认模板。
     */
    'pattern' => getenv('LOOM_DIARY_PATTERN') ?: '[{time}] {level} {event} {fields}',

    /**
     * file 驱动路径模板，支持 {date}、{level}。空则 storage/logs/diary-{date}.log（相对应用根）。
     */
    'file' => getenv('LOOM_DIARY_FILE') ?: '',

    /**
     * 文件名 {date} 的 PHP date() 格式。
     */
    'date' => getenv('LOOM_DIARY_DATE') ?: 'Y-m-d',

    /**
     * 文件名 {date} 使用的时区。
     */
    'timezone' => getenv('LOOM_DIARY_TIMEZONE') ?: 'Asia/Shanghai',

    /**
     * 最低日记级别；空表示不过滤。条目仍携带 level 字段。
     */
    'level' => getenv('LOOM_DIARY_LEVEL') ?: '',
];
