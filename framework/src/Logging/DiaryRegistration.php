<?php

declare(strict_types=1);

namespace Loom\Logging;

use Loom\Config;

/**
 * build() 前通过 DiaryBuilder 登记的自定义写入器、格式化器与命名器工厂。
 *
 * @internal
 */
final class DiaryRegistration
{
    /** @var array<string, callable(DiaryOptions, Config, array<string, mixed>): JournalWriter> */
    private array $writers = [];

    /** @var array<string, callable(DiaryOptions, Config, array<string, mixed>): JournalFormatter> */
    private array $formatters = [];

    /** @var array<string, callable(JournalEntry, array<string, mixed>): string> */
    private array $namers = [];

    /**
     * @param callable(DiaryOptions, Config, array<string, mixed>): JournalWriter $factory
     */
    public function extend(string $driver, callable $factory): void
    {
        $this->writers[$driver] = $factory;
    }

    /**
     * @param callable(DiaryOptions, Config, array<string, mixed>): JournalFormatter $factory
     */
    public function extendFormatter(string $name, callable $factory): void
    {
        $this->formatters[$name] = $factory;
    }

    /**
     * @param callable(JournalEntry, array<string, mixed>): string $factory
     */
    public function extendNamer(string $name, callable $factory): void
    {
        $this->namers[$name] = $factory;
    }

    /**
     * @return ?callable(DiaryOptions, Config, array<string, mixed>): JournalWriter
     */
    public function writerFactory(string $driver): ?callable
    {
        return $this->writers[$driver] ?? null;
    }

    /**
     * @return ?callable(DiaryOptions, Config, array<string, mixed>): JournalFormatter
     */
    public function formatterFactory(string $name): ?callable
    {
        return $this->formatters[$name] ?? null;
    }

    /**
     * @return ?callable(JournalEntry, array<string, mixed>): string
     */
    public function namerFactory(string $name): ?callable
    {
        return $this->namers[$name] ?? null;
    }
}
