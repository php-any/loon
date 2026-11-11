<?php

declare(strict_types=1);

namespace Loom\Events;

/**
 * build() 前登记的事件监听器。
 */
final class EventsRegistration
{
  /** @var array<string, list<array{type: string, listener: string|callable, method?: string, queued?: bool}>> */
    private array $listeners = [];

  /** @var array<string, true> */
    private array $pairs = [];

    public function listen(string $event, string|callable $listener, bool $queued = false): void
    {
        if ($event === '') {
            throw new \LogicException('Event class must not be empty');
        }
        if ($queued && !is_string($listener)) {
            throw new \LogicException('Queued event listeners cannot be closures');
        }
        if (is_string($listener)) {
            $key = $event . "\0" . $listener;
            if (isset($this->pairs[$key])) {
                throw new \LogicException('Duplicate listener registration: ' . $listener . ' for ' . $event);
            }
            $this->pairs[$key] = true;
            $this->listeners[$event][] = ['type' => 'class', 'listener' => $listener, 'method' => 'handle', 'queued' => $queued];
            return;
        }
        if ($queued) {
            throw new \LogicException('Queued event listeners cannot be closures');
        }
        $this->listeners[$event][] = ['type' => 'closure', 'listener' => $listener, 'queued' => false];
    }

    public function discover(string $event, string $class, string $method, bool $queued = false): void
    {
        if ($queued) {
            throw new \LogicException('Use discoverQueued for queued module listeners');
        }
        $key = $event . "\0" . $class;
        if (isset($this->pairs[$key])) {
            throw new \LogicException('Duplicate listener registration: ' . $class . ' for ' . $event);
        }
        $this->pairs[$key] = true;
        $this->listeners[$event][] = ['type' => 'class', 'listener' => $class, 'method' => $method, 'queued' => false];
    }

    public function discoverQueued(string $event, string $class, string $method): void
    {
        $key = $event . "\0" . $class;
        if (isset($this->pairs[$key])) {
            throw new \LogicException('Duplicate listener registration: ' . $class . ' for ' . $event);
        }
        $this->pairs[$key] = true;
        $this->listeners[$event][] = ['type' => 'class', 'listener' => $class, 'method' => $method, 'queued' => true];
    }

  /**
   * @return array<string, list<array{type: string, listener: string|callable, method?: string, queued?: bool}>>
   */
    public function listeners(): array
    {
        return $this->listeners;
    }
}
