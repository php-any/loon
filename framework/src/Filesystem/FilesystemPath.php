<?php

declare(strict_types=1);

namespace Loom\Filesystem;

/**
 * 对象键校验与规范化。
 */
final class FilesystemPath
{
    public static function assertKey(string $key): string
    {
        if ($key === '') {
            throw new \LogicException('Filesystem key must not be empty');
        }
        if (str_contains($key, "\0")) {
            throw new \LogicException('Filesystem key must not contain NUL');
        }
        if (str_starts_with($key, '/') || str_starts_with($key, '\\')) {
            throw new \LogicException('Filesystem key must be relative');
        }
        if (preg_match('/^[A-Za-z]:/', $key) === 1) {
            throw new \LogicException('Filesystem key must not use drive letters');
        }
        $normalized = str_replace('\\', '/', $key);
        foreach (explode('/', $normalized) as $segment) {
            if ($segment === '' || $segment === '.') {
                throw new \LogicException('Filesystem key must not contain empty path segments');
            }
            if ($segment === '..') {
                throw new \LogicException('Filesystem key must not contain ..');
            }
        }
        return $normalized;
    }
}
