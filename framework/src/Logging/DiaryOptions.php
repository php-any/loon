<?php

declare(strict_types=1);

namespace Loom\Logging;

/**
 * 日记模块选项，对应 config/diary.php。节缺失时保留类上默认值（stderr / json）。由 ApplicationBuilder 通过 bindOptional 绑定。
 *
 * @example $options = $services->get(DiaryOptions::class);
 */
final class DiaryOptions
{
    /**
     * 写入器：stderr、file 或 null（丢弃）。
     */
    public string $writer = 'stderr';

    /**
     * 格式化器：json（JSON 行）或 text（纯文本行）。
     */
    public string $formatter = 'json';

    /**
     * file 写入器使用的路径。空字符串时回落到 storage/logs/diary.log。
     */
    public string $file = '';
}
