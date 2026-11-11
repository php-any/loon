<?php

declare(strict_types=1);

namespace Loom\Filesystem;

/**
 * 按扩展名推断 Content-Type；未知类型与 .php 均回落为 application/octet-stream。
 */
final class FilesystemMime
{
    public static function fromPath(string $path): string
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if ($extension === 'php') {
            return 'application/octet-stream';
        }
        $types = [
            'css' => 'text/css',
            'js' => 'text/javascript',
            'svg' => 'image/svg+xml',
            'png' => 'image/png',
            'ico' => 'image/x-icon',
            'webp' => 'image/webp',
            'woff' => 'font/woff',
            'woff2' => 'font/woff2',
            'ttf' => 'font/ttf',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'txt' => 'text/plain; charset=utf-8',
            'html' => 'text/html; charset=utf-8',
            'json' => 'application/json; charset=utf-8',
            'pdf' => 'application/pdf',
        ];
        return $types[$extension] ?? 'application/octet-stream';
    }
}
