<?php

declare(strict_types=1);

namespace Loom;

/**
 * ApplicationBuilder::queue 上的链式配置入口。
 */
final class QueueBuilder
{
    public function __construct(private readonly QueueRegistration $registration)
    {
    }

    /**
     * 在 build() 前登记自定义队列驱动（须实现 QueueBackend）。
     *
     * @param callable(ServiceResolver): QueueBackend $factory
     */
    public function extend(string $driver, callable $factory): self
    {
        $this->registration->extend($driver, $factory);
        return $this;
    }

    public function registration(): QueueRegistration
    {
        return $this->registration;
    }
}
