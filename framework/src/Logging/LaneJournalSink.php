<?php

declare(strict_types=1);

namespace Loom\Logging;

/**
 * 单条格式化 + 写入管线。
 */
final class LaneJournalSink implements JournalSink
{
    public function __construct(
        private readonly JournalFormatter $formatter,
        private readonly JournalWriter $writer,
    ) {
    }

    public function emit(JournalEntry $entry): void
    {
        $this->writer->write($this->formatter->format($entry), $entry);
    }
}
