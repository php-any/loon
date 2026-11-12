<?php

declare(strict_types=1);

namespace Loom\Logging;

use Loom\Config;

/**
 * build() 前通过 DiaryBuilder::extend() 登记的自定义写入器驱动工厂。
 *
 * @internal
 */
final class DiaryRegistration
{
    /** @var array<string, callable(DiaryOptions, Config, array<string, mixed>): JournalWriter> */
    private array $extensions = [];

    /**
     * @param callable(DiaryOptions, Config, array<string, mixed>): JournalWriter $factory
     */
    public function extend(string $driver, callable $factory): void
    {
        $this->extensions[$driver] = $factory;
    }

    /**
     * @return ?callable(DiaryOptions, Config, array<string, mixed>): JournalWriter
     */
    public function factory(string $driver): ?callable
    {
        return $this->extensions[$driver] ?? null;
    }
}
