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
     * writer 为 stack 时的子写入器列表；每项可含 driver、formatter（覆盖全局）与 file。
     *
     * @var list<array<string, mixed>>
     */
    public array $stack = [
        ['driver' => 'stderr', 'formatter' => 'text'],
        ['driver' => 'file', 'formatter' => 'json'],
    ];

    /**
     * 格式化器：json（JSON 行）或 text（纯文本行）；stack 子项未指定时使用此默认。
     */
    public string $formatter = 'json';

    /**
     * file 写入器使用的路径。空字符串时回落到 storage/logs/diary.log。
     */
    public string $file = '';

    /**
     * 最低日记级别；空字符串表示不过滤。条目仍携带 level 字段。
     */
    public string $level = '';
}
