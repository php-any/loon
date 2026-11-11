<?php

declare(strict_types=1);

namespace Loom\Events;

/**
 * build() 前与发现阶段登记的事件监听器。
 */
final class EventsRegistration
{
    /** @var array<string, list<array{type: string, listener: string|callable, method?: string}>> */
    private array $listeners = [];

    /** @var array<string, true> */
    private array $pairs = [];

    public function listen(string $event, string|callable $listener): void
    {
        if ($event === '') {
            throw new \LogicException('Event class must not be empty');
        }
        if (is_string($listener)) {
            $key = $event . "\0" . $listener;
            if (isset($this->pairs[$key])) {
                throw new \LogicException('Duplicate listener registration: ' . $listener . ' for ' . $event);
            }
            $this->pairs[$key] = true;
            $this->listeners[$event][] = ['type' => 'class', 'listener' => $listener, 'method' => 'handle'];
            return;
        }
        $this->listeners[$event][] = ['type' => 'closure', 'listener' => $listener];
    }

    /**
     * 由模块发现登记类级监听器。
     */
    public function discover(string $event, string $class, string $method): void
    {
        $key = $event . "\0" . $class;
        if (isset($this->pairs[$key])) {
            throw new \LogicException('Duplicate listener registration: ' . $class . ' for ' . $event);
        }
        $this->pairs[$key] = true;
        $this->listeners[$event][] = ['type' => 'class', 'listener' => $class, 'method' => $method];
    }

    /**
     * @return array<string, list<array{type: string, listener: string|callable, method?: string}>>
     */
    public function listeners(): array
    {
        return $this->listeners;
    }
}
