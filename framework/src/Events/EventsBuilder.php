<?php

declare(strict_types=1);

namespace Loom\Events;

/**
 * ApplicationBuilder 上的事件总线配置入口。
 */
final class EventsBuilder
{
    public function __construct(private readonly EventsRegistration $registration)
    {
    }

    public function listen(string $event, string|callable $listener): self
    {
        $this->registration->listen($event, $listener);
        return $this;
    }

    public function registration(): EventsRegistration
    {
        return $this->registration;
    }
}
