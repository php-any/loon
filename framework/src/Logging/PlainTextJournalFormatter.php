<?php

declare(strict_types=1);

namespace Loom\Logging;

/**
 * 人类可读的纯文本行：[time] level event key=value ...
 *
 * @example $formatter->format(JournalEntry::now('error', 'job.failed', ['id' => 9]));
 */
final class PlainTextJournalFormatter implements JournalFormatter
{
    public function format(JournalEntry $entry): string
    {
        $parts = [$entry->time, $entry->level, $entry->event];
        foreach ($entry->fields as $key => $value) {
            if (is_scalar($value) || $value === null) {
                $parts[] = $key . '=' . ($value === null ? 'null' : (string) $value);
            } else {
                $encoded = json_encode($value, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES);
                $parts[] = $key . '=' . ($encoded === false ? '{}' : $encoded);
            }
        }
        return implode(' ', $parts) . "\n";
    }
}
