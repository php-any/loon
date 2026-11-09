<?php

declare(strict_types=1);

namespace Loom\Logging;

/**
 * 每行一条 JSON 对象，字段含 time、level、event，并与 fields 合并。
 *
 * @example $formatter->format(JournalEntry::now('info', 'ping', ['ok' => true]));
 */
final class JsonLineJournalFormatter implements JournalFormatter
{
    public function format(JournalEntry $entry): string
    {
        $payload = ['time' => $entry->time, 'level' => $entry->level, 'event' => $entry->event];
        foreach ($entry->fields as $key => $value) {
            $payload[$key] = $value;
        }
        return json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES) . "\n";
    }
}
