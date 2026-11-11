<?php

declare(strict_types=1);

namespace Loom\Events;

final class EventsBuilder
{
    public function __construct(private readonly EventsRegistration $registration)
    {
    }

    public function listen(string $event, string|callable $listener, bool $queued = false): self
    {
        $this->registration->listen($event, $listener, $queued);
        return $this;
    }

    public function registration(): EventsRegistration
    {
        return $this->registration;
    }
}
