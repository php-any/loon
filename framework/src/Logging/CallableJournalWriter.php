<?php

declare(strict_types=1);

namespace Loom\Logging;

/**
 * 把日记行交给调用方提供的回调，便于测试或桥接旧 callable 写法。
 *
 * @example new CallableJournalWriter(static function (string $line): void { $captured[] = $line; });
 */
final class CallableJournalWriter implements JournalWriter
{
    /** @var callable(string): void */
    private $callback;

    /**
     * @param callable(string): void $callback 接收完整日记行。
     */
    public function __construct(callable $callback)
    {
        $this->callback = $callback;
    }

    public function write(string $line): void
    {
        $writer = $this->callback;
        $writer($line);
    }
}
