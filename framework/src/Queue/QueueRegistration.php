<?php

declare(strict_types=1);

namespace Loom;

/**
 * build() 前通过 QueueBuilder::extend() 登记的队列驱动工厂。
 *
 * @internal
 */
final class QueueRegistration
{
    /** @var array<string, callable(ServiceResolver): RedisQueue> */
    private array $extensions = [];

    /**
     * @param callable(ServiceResolver): RedisQueue $factory
     */
    public function extend(string $driver, callable $factory): void
    {
        $this->extensions[$driver] = $factory;
    }

    /**
     * @return ?callable(ServiceResolver): RedisQueue
     */
    public function factory(string $driver): ?callable
    {
        return $this->extensions[$driver] ?? null;
    }
}
