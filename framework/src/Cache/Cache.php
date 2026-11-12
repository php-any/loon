<?php

declare(strict_types=1);

namespace Loom;

/**
 * 同步缓存门面：默认存储由 config/cache.php 决定，值以 JSON 信封存放以区分缺失与缓存的 null。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $cache = $scope->get(Cache::class); $cache->remember('users:1', 60, static fn (): array => ['id' => 1]);
 */
final class Cache
{
    /** @var array<string, CacheStore> */
    private array $stores = [];

    public function __construct(
        private readonly CacheOptions $options,
        private readonly CacheRegistration $registration,
    ) {
    }

    public function get(string $key): mixed
    {
        return $this->store()->get($key);
    }

    public function has(string $key): bool
    {
        return $this->store()->has($key);
    }

    public function put(string $key, mixed $value, int $ttlSeconds): void
    {
        $this->store()->put($key, $value, $ttlSeconds);
    }

    public function forever(string $key, mixed $value): void
    {
        $this->store()->forever($key, $value);
    }

    public function forget(string $key): void
    {
        $this->store()->forget($key);
    }

    /**
     * 命中时返回缓存值且不执行回调；未命中时执行回调，写入后返回。回调抛错时不写入。
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public function remember(string $key, int $ttlSeconds, callable $callback): mixed
    {
        $store = $this->store();
        if ($store->has($key)) {
            return $store->get($key);
        }
        $store->get($key);
        $value = $callback();
        $store->put($key, $value, $ttlSeconds);
        return $value;
    }

    /**
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public function rememberForever(string $key, callable $callback): mixed
    {
        $store = $this->store();
        if ($store->has($key)) {
            return $store->get($key);
        }
        $store->get($key);
        $value = $callback();
        $store->forever($key, $value);
        return $value;
    }

    public function increment(string $key, int $step = 1): int
    {
        return $this->store()->increment($key, $step);
    }

    public function decrement(string $key, int $step = 1): int
    {
        return $this->store()->decrement($key, $step);
    }

    public function store(?string $name = null): CacheStore
    {
        $name = $name ?? $this->options->default;
        if ($name === '') {
            throw new \LogicException('Cache store name must not be empty');
        }
        if (isset($this->stores[$name])) {
            return $this->stores[$name];
        }
        $config = $this->options->stores[$name] ?? null;
        if (!is_array($config)) {
            throw new \LogicException('Unknown cache store: ' . $name);
        }
        $driver = (string)($config['driver'] ?? '');
        if ($driver === '') {
            throw new \LogicException('Cache store "' . $name . '" has no driver');
        }
        $factory = $this->registration->factory($driver);
        if ($factory !== null) {
            $instance = $factory($name, $config);
        } else {
            $instance = match ($driver) {
                'array' => new ArrayCacheStore($name),
                'redis' => new RedisCacheStore($name, (string)($config['connection'] ?? '')),
                default => throw new \LogicException('Unsupported cache driver: ' . $driver),
            };
        }
        if (!$instance instanceof CacheStore) {
            throw new \LogicException('Cache driver must return CacheStore');
        }
        return $this->stores[$name] = $instance;
    }

    public function flush(): void
    {
        $this->store()->flush();
    }
}
