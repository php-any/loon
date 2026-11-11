<?php

declare(strict_types=1);

namespace Loom\Filesystem;

use Loom\HttpError;
use Loom\Request;
use Loom\Response;

/**
 * 对外提供 serve => true 磁盘的 URL 前缀下的文件，语义与 StaticFiles 一致。
 */
final class FilesystemPublicServer
{
    /** @var list<array{prefix: string, disk: FilesystemDisk}> */
    private array $served;

    /**
     * @param list<array{prefix: string, disk: FilesystemDisk}> $served
     */
    public function __construct(array $served)
    {
        $this->served = $served;
    }

    public function handle(Request $request): ?Response
    {
        $path = $request->path();
        foreach ($this->served as $entry) {
            $prefix = $entry['prefix'];
            if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
                return $this->serve($request, $prefix, $entry['disk']);
            }
        }
        return null;
    }

    private function serve(Request $request, string $prefix, FilesystemDisk $disk): Response
    {
        if (!in_array($request->method(), ['GET', 'HEAD'], true)) {
            throw new HttpError(405, 'Method Not Allowed');
        }
        $relative = substr($request->path(), strlen($prefix));
        $relative = ltrim($relative, '/');
        if ($relative === '') {
            throw new HttpError(404, 'Not Found');
        }
        try {
            $key = FilesystemPath::assertKey($relative);
        } catch (\LogicException) {
            throw new HttpError(404, 'Not Found');
        }
        $local = $disk->path($key);
        $root = $disk->path();
        $resolved = realpath($local);
        $rootResolved = realpath($root);
        if ($resolved === false || $rootResolved === false || !is_file($resolved)) {
            throw new HttpError(404, 'Not Found');
        }
        $rootNormalized = str_replace('\\', '/', $rootResolved) . '/';
        $fileNormalized = str_replace('\\', '/', $resolved);
        if (!str_starts_with($fileNormalized, $rootNormalized)) {
            throw new HttpError(404, 'Not Found');
        }
        $size = filesize($resolved);
        if ($size > 4194304) {
            throw new HttpError(413, 'File too large');
        }
        $contents = file_get_contents($resolved);
        if ($contents === false) {
            throw new HttpError(500, 'Cannot read file');
        }
        return new Response($contents, 200, ['Content-Type' => FilesystemMime::fromPath($resolved)]);
    }
}
