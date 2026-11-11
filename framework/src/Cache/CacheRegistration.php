<?php

declare(strict_types=1);

namespace Loom;

/**
 * build() 前通过 CacheBuilder::extend() 登记的自定义存储驱动工厂。
 *
 * @internal
 */
final class CacheRegistration
{
    /** @var array<string, callable(string, array): CacheStore> */
    private array $extensions = [];

    /**
     * @param callable(string $storeName, array $config): CacheStore $factory
     */
    public function extend(string $driver, callable $factory): void
    {
        $this->extensions[$driver] = $factory;
    }

    /**
     * @return ?callable(string, array): CacheStore
     */
    public function factory(string $driver): ?callable
    {
        return $this->extensions[$driver] ?? null;
    }
}
