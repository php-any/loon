<?php

declare(strict_types=1);

namespace Loom;

/**
 * 单个命名缓存存储的后端契约。
 */
interface CacheStore
{
    public function storeName(): string;

    public function get(string $key): mixed;

    public function has(string $key): bool;

    public function put(string $key, mixed $value, int $ttlSeconds): void;

    public function forever(string $key, mixed $value): void;

    public function forget(string $key): void;

    public function increment(string $key, int $step = 1): int;

    public function decrement(string $key, int $step = 1): int;

    public function flush(): void;
}
