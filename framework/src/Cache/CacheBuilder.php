<?php

declare(strict_types=1);

namespace Loom;

/**
 * ApplicationBuilder::cache 上的链式配置入口。
 */
final class CacheBuilder
{
    public function __construct(
        private readonly ServiceCollection $services,
        private readonly CacheRegistration $registration,
    ) {
    }

    /**
     * 在 build() 前登记自定义存储驱动。
     *
     * @param callable(string $storeName, array $config): CacheStore $factory
     */
    public function extend(string $driver, callable $factory): self
    {
        $this->registration->extend($driver, $factory);
        return $this;
    }

    public function registration(): CacheRegistration
    {
        return $this->registration;
    }
}
