<?php

declare(strict_types=1);

namespace Loom\Logging;

/**
 * 写入 STDERR。
 *
 * @example (new StderrJournalWriter())->write("line\n", $entry);
 */
final class StderrJournalWriter implements JournalWriter
{
    public function write(string $line, JournalEntry $entry): void
    {
        @fwrite(STDERR, $line);
    }
}
