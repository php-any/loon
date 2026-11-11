<?php

declare(strict_types=1);

namespace Loom\Events;

use Loom\Request;
use Loom\Response;
use Loom\ServiceResolver;

final class EventListenJob
{
    public function __construct(private readonly ServiceResolver $services)
    {
    }

    public function handle(Request $request): Response
    {
        $job = json_decode($request->body(), true, 64, JSON_THROW_ON_ERROR);
        $payload = is_array($job['payload'] ?? null) ? $job['payload'] : [];
        $eventClass = $payload['event'] ?? '';
        $listenerClass = $payload['listener'] ?? '';
        $method = $payload['method'] ?? 'handle';
        $data = $payload['data'] ?? [];
        if (!is_string($eventClass) || !is_string($listenerClass) || $eventClass === '' || $listenerClass === '') {
            throw new \LogicException('Invalid event listen job payload');
        }
        if (!(is_array($data) && array_is_list($data) === false)) {
            $data = is_array($data) ? $data : [];
        }
        $reflection = new \ReflectionClass($eventClass);
        if (!$reflection->implementsInterface(QueueableEvent::class)) {
            throw new \LogicException('Event must implement QueueableEvent: ' . $eventClass);
        }
        /** @var QueueableEvent $event */
        $event = $eventClass::fromQueue($data);
        $instance = $this->services->get($listenerClass);
        if (!method_exists($instance, $method)) {
            throw new \LogicException('Event listener method missing: ' . $listenerClass . '::' . $method);
        }
        $monitoring = null;
        if ($this->services->has(\Loom\Monitoring\Monitoring::class)) {
            try {
                $monitoring = $this->services->get(\Loom\Monitoring\Monitoring::class);
            } catch (\Throwable) {
                $monitoring = null;
            }
        }
        $instance->$method($event);
        if ($monitoring !== null && $monitoring->isActive()) {
            $monitoring->recordEvent('app.event.listener', [
                'name' => $eventClass,
                'listener' => $listenerClass,
                'job_id' => (int)($job['id'] ?? 0),
            ]);
        }
        return Response::json(['ok' => true]);
    }
}
