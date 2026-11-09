<?php

declare(strict_types=1);

namespace Loom\Logging;

/**
 * 一条日记的结构化内容，在格式化之前与写入目标无关。
 *
 * @example $entry = JournalEntry::now('info', 'http.request', ['status' => 200]);
 */
final class JournalEntry
{
    /**
     * @param string $time UTC 时间戳字符串。
     * @param string $level 日记级别，例如 info、warning、error。
     * @param string $event 事件标识，例如 http.request。
     * @param array<string, mixed> $fields 与事件相关的附加字段。
     */
    public function __construct(
        public readonly string $time,
        public readonly string $level,
        public readonly string $event,
        public readonly array $fields,
    ) {
    }

    /**
     * 使用当前 UTC 时间构造条目。
     *
     * @param string $level 日记级别。
     * @param string $event 事件标识。
     * @param array<string, mixed> $fields 附加字段。
     */
    public static function now(string $level, string $event, array $fields = []): self
    {
        return new self(gmdate('Y-m-d\TH:i:s\Z'), $level, $event, $fields);
    }
}
