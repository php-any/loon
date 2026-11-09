<?php

declare(strict_types=1);

namespace Loom\Logging;

/**
 * 把结构化日记条目序列化成可写入的文本。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $line = $formatter->format(JournalEntry::now('info', 'boot', []));
 */
interface JournalFormatter
{
    /**
     * @param JournalEntry $entry 时间、级别、事件名与附加字段。
     * @return string 供 JournalWriter 写出的文本，通常以换行结尾。
     */
    public function format(JournalEntry $entry): string;
}
