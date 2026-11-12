<?php

declare(strict_types=1);

namespace Loom\Logging;

/**
 * 按顺序向多个 JournalSink 发出同一条目；单个 sink 失败不得影响其余 sink。
 */
final class StackJournalSink implements JournalSink
{
    /**
     * @param list<JournalSink> $sinks
     */
    public function __construct(private readonly array $sinks)
    {
    }

    public function emit(JournalEntry $entry): void
    {
        foreach ($this->sinks as $sink) {
            try {
                $sink->emit($entry);
            } catch (\Throwable) {
                // 单个 sink 失败不得中断其余目标或业务路径。
            }
        }
    }
}
