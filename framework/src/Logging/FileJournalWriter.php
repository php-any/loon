<?php

declare(strict_types=1);

namespace Loom\Logging;

/**
 * 按命名器解析路径后以追加方式写入文件；父目录不存在时尝试创建。
 */
final class FileJournalWriter implements JournalWriter
{
    /** @var callable(JournalEntry): string */
    private $namer;

    /**
     * @param callable(JournalEntry): string $namer 由条目解析目标文件路径。
     */
    public function __construct(callable $namer)
    {
        $this->namer = $namer;
    }

    public function write(string $line, JournalEntry $entry): void
    {
        $path = ($this->namer)($entry);
        if ($path === '') {
            throw new \InvalidArgumentException('Diary file path must not be empty');
        }
        $directory = dirname($path);
        if ($directory !== '' && $directory !== '.' && !is_dir($directory)) {
            @mkdir($directory, 0775, true);
        }
        @file_put_contents($path, $line, FILE_APPEND | LOCK_EX);
    }
}
