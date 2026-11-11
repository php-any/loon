<?php

declare(strict_types=1);

namespace Loom;

use Loom\Filesystem\Filesystem;
use Loom\Filesystem\FilesystemPath;

/**
 * 一次 multipart 上传的部分。
 */
final class UploadedFile
{
    public function __construct(
        private readonly string $clientFilename,
        private readonly string $declaredContentType,
        private readonly int $size,
        private readonly string $contents,
        private readonly ?Filesystem $filesystem = null,
    ) {
    }

    public function withFilesystem(Filesystem $filesystem): self
    {
        return new self(
            $this->clientFilename,
            $this->declaredContentType,
            $this->size,
            $this->contents,
            $filesystem,
        );
    }

    public function clientFilename(): string
    {
        return $this->clientFilename;
    }

    public function contentType(): string
    {
        return $this->declaredContentType;
    }

    public function size(): int
    {
        return $this->size;
    }

    public function contents(): string
    {
        return $this->contents;
    }

  /**
   * 写入指定磁盘目录，使用随机文件名与安全扩展名。
   *
   * @return string 写入后的对象键（相对目录内路径，含子目录时使用 /）。
   */
    public function store(string $directory, ?string $disk = null): string
    {
        if ($this->filesystem === null) {
            throw new \LogicException('Filesystem is not available for this upload');
        }
        $directory = trim($directory, '/');
        if ($directory !== '') {
            $directory = FilesystemPath::assertKey($directory);
        }
        $extension = self::safeExtension($this->clientFilename);
        $name = bin2hex(random_bytes(16));
        if ($extension !== '') {
            $name .= '.' . $extension;
        }
        $key = $directory === '' ? $name : $directory . '/' . $name;
        $this->filesystem->disk($disk)->put($key, $this->contents);
        return $key;
    }

    private static function safeExtension(string $filename): string
    {
        $raw = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $safe = '';
        $length = strlen($raw);
        for ($i = 0; $i < $length; $i++) {
            $char = $raw[$i];
            if (($char >= 'a' && $char <= 'z') || ($char >= '0' && $char <= '9')) {
                $safe .= $char;
            }
        }
        return $safe;
    }
}
