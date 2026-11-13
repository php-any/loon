<?php

declare(strict_types=1);

namespace Loom\Logging;

/**
 * 人类可读的纯文本：首行 time level event 与标量字段，嵌套结构展开为缩进行。
 *
 * @example $formatter->format(JournalEntry::now('error', 'job.failed', ['id' => 9]));
 */
final class PlainTextJournalFormatter implements JournalFormatter
{
    public function format(JournalEntry $entry): string
    {
        $parts = [$entry->time, $entry->level, $entry->event];
        $split = JournalFieldText::splitScalarsAndNested($entry->fields);
        $parts = array_merge($parts, $split['headerParts']);
        $output = implode(' ', $parts);
        if ($split['extraLines'] !== []) {
            $output .= "\n" . implode("\n", $split['extraLines']);
        }

        return $output . "\n";
    }
}
