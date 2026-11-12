<?php

declare(strict_types=1);

namespace Loom\Logging;

/**
 * 日记模块选项，对应 config/diary.php。节缺失时保留类上默认值（stack / json）。由 ApplicationBuilder 通过 bindOptional 绑定。
 *
 * @example $options = $services->get(DiaryOptions::class);
 */
final class DiaryOptions
{
    /**
     * 写入器：stderr、file、null、stack，或通过 diary.extend 登记的驱动名。
     */
    public string $writer = 'stack';

    /**
     * writer 为 stack 时的子写入器列表；每项可含 driver、formatter、namer、pattern、file、date、timezone。
     *
     * @var list<array<string, mixed>>
     */
    public array $stack = [
        ['driver' => 'stderr', 'formatter' => 'text'],
        ['driver' => 'file', 'formatter' => 'json'],
    ];

    /**
     * 格式化器：json、text、pattern；stack 子项未指定时使用此默认。
     */
    public string $formatter = 'json';

    /**
     * pattern 格式化器模板；含 {time}、{level}、{event}、{fields}、{field.xxx}。
     */
    public string $pattern = '[{time}] {level} {event} {fields}';

    /**
     * file 驱动使用的命名器：内置 template，或通过 diary.extendNamer 登记的名称。
     */
    public string $namer = 'template';

    /**
     * file 驱动路径模板，支持 {date}、{level}。空则 daily 文件 storage/logs/diary-{date}.log。
     */
    public string $file = '';

    /**
     * {date} 片段的 PHP date() 格式，默认按日分文件。
     */
    public string $date = 'Y-m-d';

    /**
     * 文件名中 {date} 使用的时区；条目 time 字段仍为 UTC。
     */
    public string $timezone = 'Asia/Shanghai';

    /**
     * 最低日记级别；空字符串表示不过滤。条目仍携带 level 字段。
     */
    public string $level = '';
}
