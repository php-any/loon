<?php

declare(strict_types=1);

namespace Loom\Filesystem;

use Loom\Config;
use Loom\HttpError;
use Loom\Response;

/**
 * 本地磁盘驱动。
 */
final class LocalFilesystemDisk implements FilesystemDisk
{
    private string $absoluteRoot;
    private string $urlPrefix;

    /**
     * @param array<string, mixed> $diskConfig
     */
    public function __construct(
        private readonly Config $applicationConfig,
        private readonly string $name,
        private readonly array $diskConfig,
    ) {
        $root = (string)($diskConfig['root'] ?? '');
        if ($root === '') {
            throw new \LogicException('Local disk requires root');
        }
        $this->absoluteRoot = $applicationConfig->path($root);
        $this->urlPrefix = isset($diskConfig['url']) && is_string($diskConfig['url']) ? rtrim($diskConfig['url'], '/') : '';
    }

    public function name(): string
    {
        return $this->name;
    }

    /** @return array<string, mixed> */
    public function config(): array
    {
        return $this->diskConfig;
    }

    public function put(string $key, string $contents): void
    {
        $key = FilesystemPath::assertKey($key);
        $path = $this->absolutePath($key);
        $parent = dirname($path);
        if (!is_dir($parent)) {
            if (!mkdir($parent, 0777, true) && !is_dir($parent)) {
                throw new \RuntimeException('Cannot create directory: ' . $parent);
            }
        }
        if (file_put_contents($path, $contents) === false) {
            throw new \RuntimeException('Cannot write file: ' . $key);
        }
    }

    public function get(string $key): string
    {
        $key = FilesystemPath::assertKey($key);
        $path = $this->absolutePath($key);
        if (!is_file($path)) {
            throw new \RuntimeException('File does not exist: ' . $key);
        }
        $this->assertResolvedUnderRoot($path);
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new \RuntimeException('Cannot read file: ' . $key);
        }
        return $contents;
    }

    public function exists(string $key): bool
    {
        try {
            $key = FilesystemPath::assertKey($key);
        } catch (\LogicException) {
            return false;
        }
        return is_file($this->absolutePath($key));
    }

    public function delete(string $key): void
    {
        $key = FilesystemPath::assertKey($key);
        $path = $this->absolutePath($key);
        if (is_file($path)) {
            unlink($path);
        }
    }

    public function copy(string $from, string $to): void
    {
        $this->put($to, $this->get($from));
    }

    public function move(string $from, string $to): void
    {
        $fromKey = FilesystemPath::assertKey($from);
        $toKey = FilesystemPath::assertKey($to);
        $fromPath = $this->absolutePath($fromKey);
        $toPath = $this->absolutePath($toKey);
        $parent = dirname($toPath);
        if (!is_dir($parent)) {
            if (!mkdir($parent, 0777, true) && !is_dir($parent)) {
                throw new \RuntimeException('Cannot create directory: ' . $parent);
            }
        }
        if (!is_file($fromPath)) {
            throw new \RuntimeException('File does not exist: ' . $from);
        }
        if (!rename($fromPath, $toPath)) {
            throw new \RuntimeException('Cannot move file: ' . $from);
        }
    }

    public function files(string $directory = ''): array
    {
        if ($directory !== '') {
            $directory = FilesystemPath::assertKey($directory);
        }
        $dir = $directory === '' ? $this->absoluteRoot : $this->absolutePath($directory);
        if (!is_dir($dir)) {
            return [];
        }
        $out = [];
        $prefix = $directory === '' ? '' : $directory . '/';
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = $dir . DIRECTORY_SEPARATOR . $entry;
            if (is_file($full)) {
                $out[] = $prefix . str_replace('\\', '/', $entry);
            }
        }
        sort($out);
        return $out;
    }

    public function makeDirectory(string $directory): void
    {
        $directory = FilesystemPath::assertKey($directory);
        $path = $this->absolutePath($directory);
        if (is_dir($path)) {
            return;
        }
        if (!mkdir($path, 0777, true) && !is_dir($path)) {
            throw new \RuntimeException('Cannot create directory: ' . $directory);
        }
    }

    public function path(string $key = ''): string
    {
        if ($key === '') {
            return $this->absoluteRoot;
        }
        return $this->absolutePath(FilesystemPath::assertKey($key));
    }

    public function url(string $key): string
    {
        if ($this->urlPrefix === '') {
            throw new \LogicException('Disk "' . $this->name . '" has no public url');
        }
        $key = FilesystemPath::assertKey($key);
        return $this->urlPrefix . '/' . $key;
    }

    public function response(string $key): Response
    {
        $key = FilesystemPath::assertKey($key);
        $path = $this->absolutePath($key);
        if (!is_file($path)) {
            throw new HttpError(404, 'Not Found');
        }
        $size = filesize($path);
        if ($size > 4194304) {
            throw new HttpError(413, 'File too large');
        }
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new HttpError(500, 'Cannot read file');
        }
        return new Response($contents, 200, ['Content-Type' => FilesystemMime::fromPath($path)]);
    }

    private function absolutePath(string $key): string
    {
        return $this->absoluteRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $key);
    }

    private function assertResolvedUnderRoot(string $path): void
    {
        if (!is_file($path) && !is_dir($path)) {
            return;
        }
        $root = realpath($this->absoluteRoot);
        $resolved = realpath($path);
        if ($root === false || $resolved === false) {
            return;
        }
        $rootPrefix = str_replace('\\', '/', $root) . '/';
        $resolvedPath = str_replace('\\', '/', $resolved);
        if (!str_starts_with($resolvedPath, $rootPrefix)) {
            throw new \LogicException('Path escapes filesystem root');
        }
    }
}
