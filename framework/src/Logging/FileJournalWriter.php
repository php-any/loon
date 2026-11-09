<?php

declare(strict_types=1);

namespace Loom\Logging;

/**
 * 以追加方式写入文件。路径不存在时尝试创建父目录。
 *
 * 构造参数：
 * - $path（string）：目标文件的绝对或相对路径。
 */
final class FileJournalWriter implements JournalWriter
{
    private string $path;

    public function __construct(string $path)
    {
        if ($path === '') {
            throw new \InvalidArgumentException('Diary file path must not be empty');
        }
        $this->path = $path;
    }

    public function write(string $line): void
    {
        $directory = dirname($this->path);
        if ($directory !== '' && $directory !== '.' && !is_dir($directory)) {
            @mkdir($directory, 0775, true);
        }
        @file_put_contents($this->path, $line, FILE_APPEND | LOCK_EX);
    }
}
