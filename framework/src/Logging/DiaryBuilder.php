<?php

declare(strict_types=1);

namespace Loom\Logging;

/**
 * ApplicationBuilder::diary 上的链式配置入口。
 */
final class DiaryBuilder
{
    public function __construct(private readonly DiaryRegistration $registration)
    {
    }

    /**
     * 在 build() 前登记自定义日记写入器驱动。
     *
     * @param callable(DiaryOptions, \Loom\Config, array<string, mixed>): JournalWriter $factory 第三参数为 stack 子项配置；单写入器时为空数组。
     */
    public function extend(string $driver, callable $factory): self
    {
        $this->registration->extend($driver, $factory);
        return $this;
    }

    /**
     * 在 build() 前登记自定义日记格式化器。
     *
     * @param callable(DiaryOptions, \Loom\Config, array<string, mixed>): JournalFormatter $factory
     */
    public function extendFormatter(string $name, callable $factory): self
    {
        $this->registration->extendFormatter($name, $factory);
        return $this;
    }

    /**
     * 在 build() 前登记自定义文件路径命名器；仍使用内置 file 写入器的追加与建目录逻辑。
     *
     * @param callable(JournalEntry, array<string, mixed>): string $factory
     */
    public function extendNamer(string $name, callable $factory): self
    {
        $this->registration->extendNamer($name, $factory);
        return $this;
    }

    public function registration(): DiaryRegistration
    {
        return $this->registration;
    }
}
