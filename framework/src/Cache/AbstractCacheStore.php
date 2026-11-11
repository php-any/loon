<?php

declare(strict_types=1);

namespace Loom;

use Loom\Monitoring\ActiveMonitoring;

/**
 * JSON 信封序列化与监控事件记录的共享逻辑。
 *
 * @internal
 */
abstract class AbstractCacheStore implements CacheStore
{
    private const ENVELOPE_MARKER = 'loom_cache';

    public function __construct(protected readonly string $name)
    {
    }

    public function storeName(): string
    {
        return $this->name;
    }

    public function get(string $key): mixed
    {
        $raw = $this->readRaw($key);
        if ($raw === null) {
            $this->recordMiss($key);
            return null;
        }
        $this->recordHit($key);
        if ($this->isCounterRaw($raw)) {
            return $this->parseCounter($raw);
        }
        return $this->decodeEnvelope($raw);
    }

    public function has(string $key): bool
    {
        return $this->readRaw($key) !== null;
    }

    public function put(string $key, mixed $value, int $ttlSeconds): void
    {
        if ($ttlSeconds < 1) {
            throw new \InvalidArgumentException('Cache TTL must be at least 1 second');
        }
        $this->assertEnvelopeValue($key, $value);
        $payload = $this->encodeEnvelope($value);
        $this->writeRaw($key, $payload, $ttlSeconds);
        $this->record('cache.write', ['store' => $this->name, 'key' => $key, 'ttl' => $ttlSeconds]);
    }

    public function forever(string $key, mixed $value): void
    {
        $this->assertEnvelopeValue($key, $value);
        $payload = $this->encodeEnvelope($value);
        $this->writeRaw($key, $payload, null);
        $this->record('cache.write', ['store' => $this->name, 'key' => $key, 'ttl' => null]);
    }

    public function forget(string $key): void
    {
        $this->deleteRaw($key);
        $this->record('cache.forget', ['store' => $this->name, 'key' => $key]);
    }

    public function increment(string $key, int $step = 1): int
    {
        $raw = $this->readRaw($key);
        if ($raw !== null && $this->isEnvelopeRaw($raw)) {
            throw new \LogicException('Cache key uses JSON envelope; cannot increment');
        }
        $next = $this->incrementRaw($key, $step);
        $this->record('cache.increment', ['store' => $this->name, 'key' => $key, 'step' => $step]);
        return $next;
    }

    public function decrement(string $key, int $step = 1): int
    {
        $raw = $this->readRaw($key);
        if ($raw !== null && $this->isEnvelopeRaw($raw)) {
            throw new \LogicException('Cache key uses JSON envelope; cannot decrement');
        }
        $next = $this->incrementRaw($key, -$step);
        $this->record('cache.increment', ['store' => $this->name, 'key' => $key, 'step' => -$step]);
        return $next;
    }

    public function flush(): void
    {
        $this->flushRaw();
        $this->record('cache.flush', ['store' => $this->name]);
    }

    public function recordHit(string $key): void
    {
        $this->record('cache.hit', ['store' => $this->name, 'key' => $key, 'hit' => true]);
    }

    public function recordMiss(string $key): void
    {
        $this->record('cache.miss', ['store' => $this->name, 'key' => $key, 'hit' => false]);
    }

    /** @param array<string, scalar|null> $data */
    protected function record(string $event, array $data): void
    {
        $monitoring = ActiveMonitoring::current();
        if ($monitoring === null || !$monitoring->isActive()) {
            return;
        }
        $monitoring->recordEvent($event, $data);
    }

    protected function assertEnvelopeValue(string $key, mixed $value): void
    {
        if ($value === null) {
            return;
        }
        if (is_scalar($value)) {
            return;
        }
        if (is_array($value)) {
            $this->assertArrayValue($value, $key);
            return;
        }
        throw new \LogicException('Cache only supports scalar values, arrays, and null');
    }

    /** @param array<mixed> $value */
    private function assertArrayValue(array $value, string $key): void
    {
        foreach ($value as $item) {
            if ($item === null || is_scalar($item)) {
                continue;
            }
            if (is_array($item)) {
                $this->assertArrayValue($item, $key);
                continue;
            }
            throw new \LogicException('Cache only supports scalar values, arrays, and null');
        }
    }

    protected function encodeEnvelope(mixed $value): string
    {
        $json = json_encode(['m' => self::ENVELOPE_MARKER, 'v' => $value], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
        return $json;
    }

    protected function decodeEnvelope(string $raw): mixed
    {
        if (!$this->isEnvelopeRaw($raw)) {
            throw new \LogicException('Cache value is not a JSON envelope');
        }
        /** @var array{m?: string, v?: mixed} $decoded */
        $decoded = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        if (($decoded['m'] ?? '') !== self::ENVELOPE_MARKER) {
            throw new \LogicException('Cache value is not a JSON envelope');
        }
        return $decoded['v'] ?? null;
    }

    protected function isEnvelopeRaw(string $raw): bool
    {
        if ($raw === '' || $raw[0] !== '{') {
            return false;
        }
        try {
            /** @var array{m?: string} $decoded */
            $decoded = json_decode($raw, true, 64);
            return is_array($decoded) && ($decoded['m'] ?? '') === self::ENVELOPE_MARKER;
        } catch (\Throwable) {
            return false;
        }
    }

    protected function isCounterRaw(string $raw): bool
    {
        return !$this->isEnvelopeRaw($raw) && preg_match('/^-?\d+$/D', $raw) === 1;
    }

    protected function parseCounter(string $raw): int
    {
        return (int)$raw;
    }

    abstract protected function readRaw(string $key): ?string;

    /** @param ?int $ttlSeconds null 表示永不过期 */
    abstract protected function writeRaw(string $key, string $payload, ?int $ttlSeconds): void;

    abstract protected function deleteRaw(string $key): void;

    abstract protected function incrementRaw(string $key, int $step): int;

    abstract protected function flushRaw(): void;
}
