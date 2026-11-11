<?php

declare(strict_types=1);

namespace Loom\Filesystem;

/**
 * 校验本地路径经 realpath 后仍严格落在磁盘根目录内（防符号链接越界）。
 */
final class FilesystemRootGuard
{
    public static function resolveRoot(string $absoluteRoot): string
    {
        $resolved = realpath($absoluteRoot);
        if ($resolved === false) {
            if (!is_dir($absoluteRoot) && !mkdir($absoluteRoot, 0777, true) && !is_dir($absoluteRoot)) {
                throw new \LogicException('Filesystem root is not available');
            }
            $resolved = realpath($absoluteRoot);
        }
        if ($resolved === false || !is_dir($resolved)) {
            throw new \LogicException('Filesystem root is not available');
        }
        return $resolved;
    }

    /**
     * 已有文件（含符号链接）须能 realpath 且落在根下。
     *
     * @return string 可用于读写的 canonical 路径
     */
    public static function resolveExistingFile(string $absoluteRoot, string $absolutePath): string
    {
        if (!is_file($absolutePath) && !is_link($absolutePath)) {
            throw new \RuntimeException('File does not exist');
        }
        return self::resolveExistingPath($absoluteRoot, $absolutePath, true);
    }

    /**
     * @return string canonical 目录路径
     */
    public static function resolveExistingDirectory(string $absoluteRoot, string $absolutePath): string
    {
        if (!is_dir($absolutePath) && !is_link($absolutePath)) {
            throw new \RuntimeException('Directory does not exist');
        }
        return self::resolveExistingPath($absoluteRoot, $absolutePath, false);
    }

    /**
     * 写入、删除、移动前：目标若已存在则须落在根下；不存在时其父目录须落在根下。
     */
    public static function assertMutationPath(string $absoluteRoot, string $absolutePath): void
    {
        $root = self::resolveRoot($absoluteRoot);
        if (is_file($absolutePath) || is_link($absolutePath) || is_dir($absolutePath)) {
            self::resolveExistingPath($absoluteRoot, $absolutePath, false);
            return;
        }
        if (!self::pathWithinConfiguredRoot($absoluteRoot, $absolutePath)) {
            throw new \LogicException('Path escapes filesystem root');
        }
        $parent = dirname($absolutePath);
        while (!is_dir($parent) && self::pathWithinConfiguredRoot($absoluteRoot, $parent)) {
            $next = dirname($parent);
            if ($next === $parent) {
                break;
            }
            $parent = $next;
        }
        if (!is_dir($parent)) {
            return;
        }
        $resolvedParent = realpath($parent);
        if ($resolvedParent === false) {
            throw new \LogicException('Path escapes filesystem root');
        }
        self::assertResolvedUnderRoot($root, $resolvedParent);
    }

    /**
     * @throws \LogicException 越界或无法解析
     */
    private static function resolveExistingPath(string $absoluteRoot, string $absolutePath, bool $requireFile): string
    {
        $root = self::resolveRoot($absoluteRoot);
        if (!file_exists($absolutePath) && !is_link($absolutePath)) {
            throw new \RuntimeException('File does not exist');
        }
        $resolved = realpath($absolutePath);
        if ($resolved === false) {
            throw new \LogicException('Path escapes filesystem root');
        }
        if ($requireFile && !is_file($resolved)) {
            throw new \RuntimeException('File does not exist');
        }
        if (!$requireFile && !is_file($resolved) && !is_dir($resolved)) {
            throw new \LogicException('Path escapes filesystem root');
        }
        self::assertResolvedUnderRoot($root, $resolved);
        return $resolved;
    }

    private static function assertResolvedUnderRoot(string $root, string $resolved): void
    {
        $rootNormalized = str_replace('\\', '/', $root);
        $resolvedNormalized = str_replace('\\', '/', $resolved);
        $rootPrefix = $rootNormalized . '/';
        if ($resolvedNormalized !== $rootNormalized && !str_starts_with($resolvedNormalized, $rootPrefix)) {
            throw new \LogicException('Path escapes filesystem root');
        }
    }

    private static function pathWithinConfiguredRoot(string $absoluteRoot, string $path): bool
    {
        $rootBase = rtrim(str_replace('\\', '/', $absoluteRoot), '/');
        $pathNorm = str_replace('\\', '/', $path);
        return $pathNorm === $rootBase || str_starts_with($pathNorm, $rootBase . '/');
    }
}
