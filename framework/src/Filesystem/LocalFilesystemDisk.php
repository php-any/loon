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
        try {
            FilesystemRootGuard::assertMutationPath($this->absoluteRoot, $path);
        } catch (\LogicException $error) {
            throw new \RuntimeException('Cannot write file: ' . $key, 0, $error);
        }
        $parent = dirname($path);
        if (!is_dir($parent)) {
            if (!mkdir($parent, 0777, true) && !is_dir($parent)) {
                throw new \RuntimeException('Cannot create directory: ' . $parent);
            }
        }
        if (file_put_contents($path, $contents) === false) {
            throw new \RuntimeException('Cannot write file: ' . $key);
        }
        FilesystemMonitor::write($this->name, $key, strlen($contents));
    }

    public function get(string $key): string
    {
        $key = FilesystemPath::assertKey($key);
        $path = $this->absolutePath($key);
        try {
            $resolved = FilesystemRootGuard::resolveExistingFile($this->absoluteRoot, $path);
        } catch (\LogicException) {
            throw new \RuntimeException('Cannot read file: ' . $key);
        } catch (\RuntimeException $error) {
            throw new \RuntimeException('File does not exist: ' . $key, 0, $error);
        }
        $contents = file_get_contents($resolved);
        if ($contents === false) {
            throw new \RuntimeException('Cannot read file: ' . $key);
        }
        FilesystemMonitor::read($this->name, $key, strlen($contents));
        return $contents;
    }

    public function exists(string $key): bool
    {
        try {
            $key = FilesystemPath::assertKey($key);
            $path = $this->absolutePath($key);
            FilesystemRootGuard::resolveExistingFile($this->absoluteRoot, $path);
            return true;
        } catch (\LogicException | \RuntimeException) {
            return false;
        }
    }

    public function delete(string $key): void
    {
        $key = FilesystemPath::assertKey($key);
        $path = $this->absolutePath($key);
        try {
            $resolved = FilesystemRootGuard::resolveExistingFile($this->absoluteRoot, $path);
        } catch (\LogicException) {
            return;
        } catch (\RuntimeException) {
            return;
        }
        unlink($resolved);
        FilesystemMonitor::delete($this->name, $key);
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
        try {
            $resolvedFrom = FilesystemRootGuard::resolveExistingFile($this->absoluteRoot, $fromPath);
            FilesystemRootGuard::assertMutationPath($this->absoluteRoot, $toPath);
        } catch (\LogicException $error) {
            throw new \RuntimeException('Cannot move file: ' . $from, 0, $error);
        } catch (\RuntimeException $error) {
            throw new \RuntimeException('File does not exist: ' . $from, 0, $error);
        }
        $parent = dirname($toPath);
        if (!is_dir($parent)) {
            if (!mkdir($parent, 0777, true) && !is_dir($parent)) {
                throw new \RuntimeException('Cannot create directory: ' . $parent);
            }
        }
        if (!rename($resolvedFrom, $toPath)) {
            throw new \RuntimeException('Cannot move file: ' . $from);
        }
        $size = filesize($toPath);
        FilesystemMonitor::write($this->name, $toKey, is_int($size) ? $size : 0);
        FilesystemMonitor::delete($this->name, $fromKey);
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
        try {
            $dir = FilesystemRootGuard::resolveExistingDirectory($this->absoluteRoot, $dir);
        } catch (\LogicException) {
            return [];
        }
        $out = [];
        $prefix = $directory === '' ? '' : $directory . '/';
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = $dir . DIRECTORY_SEPARATOR . $entry;
            if (!is_file($full) && !is_link($full)) {
                continue;
            }
            try {
                FilesystemRootGuard::resolveExistingFile($this->absoluteRoot, $full);
            } catch (\LogicException | \RuntimeException) {
                continue;
            }
            $out[] = $prefix . str_replace('\\', '/', $entry);
        }
        sort($out);
        return $out;
    }

    public function makeDirectory(string $directory): void
    {
        $directory = FilesystemPath::assertKey($directory);
        $path = $this->absolutePath($directory);
        try {
            FilesystemRootGuard::assertMutationPath($this->absoluteRoot, $path);
        } catch (\LogicException $error) {
            throw new \RuntimeException('Cannot create directory: ' . $directory, 0, $error);
        }
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
        try {
            $resolved = FilesystemRootGuard::resolveExistingFile($this->absoluteRoot, $path);
        } catch (\LogicException) {
            throw new HttpError(404, 'Not Found');
        } catch (\RuntimeException) {
            throw new HttpError(404, 'Not Found');
        }
        $size = filesize($resolved);
        if (is_int($size) && $size > 4194304) {
            throw new HttpError(413, 'File too large');
        }
        $contents = file_get_contents($resolved);
        if ($contents === false) {
            throw new HttpError(500, 'Cannot read file');
        }
        FilesystemMonitor::serve($this->name, $key, is_int($size) ? $size : 0);
        return new Response($contents, 200, ['Content-Type' => FilesystemMime::fromPath($resolved)]);
    }

    private function absolutePath(string $key): string
    {
        return $this->absoluteRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $key);
    }
}
