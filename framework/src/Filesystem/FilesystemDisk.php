<?php

declare(strict_types=1);

namespace Loom\Filesystem;

use Loom\HttpError;
use Loom\Response;

/**
 * 单个命名磁盘的读写 API。
 */
interface FilesystemDisk
{
    public function put(string $key, string $contents): void;

    public function get(string $key): string;

    public function exists(string $key): bool;

    public function delete(string $key): void;

    public function copy(string $from, string $to): void;

    public function move(string $from, string $to): void;

    /**
     * @return list<string> 单层目录下的对象键（相对路径，使用 /）。
     */
    public function files(string $directory = ''): array;

    public function makeDirectory(string $directory): void;

    /** 磁盘根目录下的绝对本地路径。 */
    public function path(string $key = ''): string;

    /** 仅当磁盘配置了 url 前缀时可用。 */
    public function url(string $key): string;

    /** 在应用内鉴权后发送私有文件，超过 4 MiB 时拒绝。 */
    public function response(string $key): Response;

    public function name(): string;

    /** @return array<string, mixed> */
    public function config(): array;
}
