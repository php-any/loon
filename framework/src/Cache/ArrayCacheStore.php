<?php

declare(strict_types=1);

namespace Loom;

/**
 * 进程内数组存储，供测试与无 Redis 场景使用。
 */
final class ArrayCacheStore extends AbstractCacheStore
{
    /** @var array<string, array{payload: string, expires: ?float}> */
    private array $entries = [];

    protected function readRaw(string $key): ?string
    {
        $entry = $this->entries[$key] ?? null;
        if ($entry === null) {
            return null;
        }
        if ($entry['expires'] !== null && microtime(true) >= $entry['expires']) {
            unset($this->entries[$key]);
            return null;
        }
        return $entry['payload'];
    }

    protected function writeRaw(string $key, string $payload, ?int $ttlSeconds): void
    {
        $expires = $ttlSeconds === null ? null : microtime(true) + $ttlSeconds;
        $this->entries[$key] = ['payload' => $payload, 'expires' => $expires];
    }

    protected function deleteRaw(string $key): void
    {
        unset($this->entries[$key]);
    }

    protected function incrementRaw(string $key, int $step): int
    {
        $expires = null;
        $entry = $this->entries[$key] ?? null;
        if ($entry !== null) {
            if ($entry['expires'] !== null && microtime(true) >= $entry['expires']) {
                $entry = null;
            } else {
                $expires = $entry['expires'];
            }
        }
        $raw = $entry === null ? null : $entry['payload'];
        $current = $raw === null ? 0 : (int)$raw;
        $next = $current + $step;
        $this->entries[$key] = ['payload' => (string)$next, 'expires' => $expires];
        return $next;
    }

    protected function flushRaw(): void
    {
        $this->entries = [];
    }
}
