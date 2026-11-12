<?php

declare(strict_types=1);

namespace Loom\Logging;

/**
 * 从结构化条目写出日记；用于 stack 中按目标使用不同 Formatter。
 */
interface JournalSink
{
    public function emit(JournalEntry $entry): void;
}
