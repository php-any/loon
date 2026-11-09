<?php

declare(strict_types=1);

namespace Loom\Logging;

/**
 * 丢弃所有日记输出，用于测试或显式禁用日记。
 *
 * @example (new NullJournalWriter())->write("ignored\n");
 */
final class NullJournalWriter implements JournalWriter
{
    public function write(string $line): void
    {
    }
}
