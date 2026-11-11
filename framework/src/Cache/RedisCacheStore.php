<?php

declare(strict_types=1);

namespace Loom;

/**
 * 基于 Loom\Redis 的缓存存储；键使用 cache:{store}: 前缀，与会话/队列键隔离。
 */
final class RedisCacheStore extends AbstractCacheStore
{
    protected function redisKey(string $logicalKey): string
    {
        return Redis::key('cache:' . $this->name . ':' . $logicalKey);
    }

    protected function flushPattern(): string
    {
        return Redis::key('cache:' . $this->name . ':*');
    }

    protected function readRaw(string $key): ?string
    {
        $value = Redis::command(['GET', $this->redisKey($key)]);
        if ($value === null) {
            return null;
        }
        if (!is_string($value)) {
            throw new \LogicException('Cache Redis value must be a string');
        }
        return $value;
    }

    protected function writeRaw(string $key, string $payload, ?int $ttlSeconds): void
    {
        $redisKey = $this->redisKey($key);
        if ($ttlSeconds === null) {
            Redis::command(['SET', $redisKey, $payload]);
            return;
        }
        Redis::command(['SETEX', $redisKey, (string)$ttlSeconds, $payload]);
    }

    protected function deleteRaw(string $key): void
    {
        Redis::command(['DEL', $this->redisKey($key)]);
    }

    protected function incrementRaw(string $key, int $step): int
    {
        $redisKey = $this->redisKey($key);
        if ($step === 1) {
            $value = Redis::command(['INCR', $redisKey]);
        } elseif ($step === -1) {
            $value = Redis::command(['DECR', $redisKey]);
        } else {
            $value = Redis::command(['INCRBY', $redisKey, (string)$step]);
        }
        if ($value === null || !(is_int($value) || is_string($value))) {
            throw new \LogicException('Cache increment failed');
        }
        return (int)$value;
    }

    protected function flushRaw(): void
    {
        $pattern = $this->flushPattern();
        Redis::using(static function (\Redis $client) use ($pattern): void {
            $cursor = '0';
            do {
                $chunk = Redis::execute($client, ['SCAN', $cursor, 'MATCH', $pattern, 'COUNT', '128']);
                if (!is_array($chunk) || count($chunk) < 2) {
                    break;
                }
                $next = $chunk[0];
                $keys = $chunk[1];
                $cursor = is_string($next) ? $next : (string)$next;
                if (!is_array($keys) || $keys === []) {
                    continue;
                }
                $batch = [];
                foreach ($keys as $key) {
                    if (is_string($key) && $key !== '') {
                        $batch[] = $key;
                    }
                }
                for ($offset = 0; $offset < count($batch); $offset += 128) {
                    $slice = array_slice($batch, $offset, 128);
                    if ($slice === []) {
                        continue;
                    }
                    Redis::execute($client, array_merge(['DEL'], $slice));
                }
            } while ($cursor !== '0');
        });
    }
}
