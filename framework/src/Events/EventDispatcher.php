<?php

declare(strict_types=1);

namespace Loom\Events;

use Loom\Monitoring\Monitoring;
use Loom\ServiceResolver;

/**
 * 进程内同步应用事件总线。负载宜为标量或 id，不要放 Model 实例。
 */
final class EventDispatcher
{
    /** @var list<string> */
    private array $dispatchStack = [];

    /**
     * @param array<string, list<array{type: string, listener: string|callable, method?: string}>> $listeners
     */
    public function __construct(
        private readonly array $listeners,
        private readonly ServiceResolver $services,
    ) {
    }

    public function dispatch(object $event): void
    {
        $name = $event::class;
        if (in_array($name, $this->dispatchStack, true)) {
            throw new \LogicException('Re-entrant dispatch of the same event class: ' . $name);
        }
        $entries = $this->listeners[$name] ?? [];
        if ($entries === []) {
            return;
        }
        $this->dispatchStack[] = $name;
        $monitoring = null;
        if ($this->services->has(Monitoring::class)) {
            try {
                $monitoring = $this->services->get(Monitoring::class);
            } catch (\Throwable) {
                $monitoring = null;
            }
        }
        try {
            if ($monitoring !== null && $monitoring->isActive()) {
                $monitoring->recordEvent('app.event', ['name' => $name, 'listeners' => count($entries)]);
            }
            foreach ($entries as $entry) {
                if ($entry['type'] === 'closure') {
                    $listener = $entry['listener'];
                    if (!is_callable($listener)) {
                        throw new \LogicException('Event listener is not callable');
                    }
                    $listener($event);
                    continue;
                }
                $class = $entry['listener'];
                $method = $entry['method'] ?? 'handle';
                if (!is_string($class)) {
                    throw new \LogicException('Invalid listener class');
                }
                $instance = $this->services->get($class);
                if (!method_exists($instance, $method)) {
                    throw new \LogicException('Event listener method missing: ' . $class . '::' . $method);
                }
                $instance->$method($event);
            }
        } finally {
            array_pop($this->dispatchStack);
        }
    }
}
