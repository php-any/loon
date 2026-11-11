<?php

declare(strict_types=1);

namespace Loom\Events;

use Loom\Monitoring\Monitoring;
use Loom\Queue;
use Loom\ServiceResolver;

/**
 * 同步监听器当场执行；queued 监听器写入 Redis Stream。
 */
final class EventDispatcher
{
  /** @var list<string> */
    private array $dispatchStack = [];

  /**
   * @param array<string, list<array{type: string, listener: string|callable, method?: string, queued?: bool}>> $listeners
   */
    public function __construct(
        private readonly array $listeners,
        private readonly ServiceResolver $services,
        private readonly Queue $queue,
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
                if ($entry['queued'] ?? false) {
                    if (!$event instanceof QueueableEvent) {
                        throw new \LogicException('Queued listener requires QueueableEvent: ' . $name);
                    }
                    $owner = method_exists($event, 'queueOwner') ? (int)$event->queueOwner() : 0;
                    $taskId = $this->queue->dispatch('loom.event.listen', [
                        'event' => $name,
                        'listener' => $class,
                        'method' => $method,
                        'data' => $event->toQueue(),
                    ], $owner);
                    if ($monitoring !== null && $monitoring->isActive()) {
                        $monitoring->recordEvent('app.event.queued', [
                            'name' => $name,
                            'listener' => $class,
                            'job_id' => $taskId,
                            'owner' => $owner,
                        ]);
                    }
                    continue;
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
