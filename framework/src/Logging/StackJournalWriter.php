<?php

declare(strict_types=1);

namespace Loom\Logging;

/**
 * 按登记顺序向多个 JournalWriter 写出同一行；单个目标失败不得影响其余目标。
 */
final class StackJournalWriter implements JournalWriter
{
    /**
     * @param list<JournalWriter> $writers
     */
    public function __construct(private readonly array $writers)
    {
    }

    public function write(string $line): void
    {
        foreach ($this->writers as $writer) {
            try {
                $writer->write($line);
            } catch (\Throwable) {
                // 单个写入目标失败不得中断其余目标或业务路径。
            }
        }
    }
}
