<?php

declare(strict_types=1);

namespace Loom\Logging;

/**
 * 丢弃所有日记输出。
 *
 * @example (new NullJournalWriter())->write("ignored\n", $entry);
 */
final class NullJournalWriter implements JournalWriter
{
    public function write(string $line, JournalEntry $entry): void
    {
    }
}
