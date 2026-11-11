<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

function queueEventsCheck(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

final class QueueEventsTestEvent implements Loom\Events\QueueableEvent
{
    public function __construct(public int $orderId)
    {
    }

    public function toQueue(): array
    {
        return ['orderId' => $this->orderId];
    }

    public static function fromQueue(array $data): static
    {
        return new self((int)($data['orderId'] ?? 0));
    }

    public function queueOwner(): int
    {
        return 7;
    }
}

final class QueueEventsSyncListener
{
    public static int $sync = 0;

    public function handle(QueueEventsTestEvent $event): void
    {
        self::$sync = $event->orderId;
    }
}

final class QueueEventsQueuedListener
{
    public static int $queued = 0;

    public function handle(QueueEventsTestEvent $event): void
    {
        self::$queued = $event->orderId;
    }
}

final class QueueEventsFailingListener
{
    public function handle(QueueEventsTestEvent $event): void
    {
        throw new RuntimeException('sync failed');
    }
}

$root = sys_get_temp_dir() . '/loom-queue-events-' . bin2hex(random_bytes(6));
mkdir($root . '/config', 0700, true);

$lines = [];
$builder = Loom\Application::createBuilder($root);
$builder->services->addScoped(QueueEventsSyncListener::class);
$builder->services->addScoped(QueueEventsQueuedListener::class);
$builder->services->addScoped(QueueEventsFailingListener::class);
$builder->events
    ->listen(QueueEventsTestEvent::class, QueueEventsSyncListener::class)
    ->listen(QueueEventsTestEvent::class, QueueEventsQueuedListener::class, queued: true);
$closureRejected = false;
try {
    $builder->events->listen(QueueEventsTestEvent::class, static function (): void {}, queued: true);
} catch (LogicException) {
    $closureRejected = true;
}
queueEventsCheck($closureRejected, 'closure queued listener rejected at registration');

final class QueueEventsPlain
{
}

$missingContract = false;
try {
    $bad = Loom\Application::createBuilder($root);
    $bad->events->listen(QueueEventsPlain::class, QueueEventsQueuedListener::class, queued: true);
    $bad->build();
} catch (LogicException) {
    $missingContract = true;
}
queueEventsCheck($missingContract, 'queued listener without QueueableEvent fails build');

$builder->monitoring->replaceMonitoring(static function (Loom\ServiceResolver $resolver) use (&$lines): Loom\Monitoring\Monitoring {
    $configured = new Loom\Monitoring\ConfigurableMonitoring(
        $resolver->get(Loom\Monitoring\MonitoringOptions::class),
        $resolver->get(Loom\Monitoring\MonitoringRegistration::class),
        null,
        null,
        static function (string $line) use (&$lines): void {
            $lines[] = $line;
        },
    );
    $configured->bindScope($resolver->get(Loom\ServiceScope::class));
    return $configured;
});

$redis = getenv('LOOM_TEST_REDIS') === '1';
if (!$redis) {
    echo "loom_queue_events_test: OK (skipped Redis assertions; set LOOM_TEST_REDIS=1 for full run)\n";
    return;
}

$app = $builder->build();
QueueEventsSyncListener::$sync = 0;
QueueEventsQueuedListener::$queued = 0;
$app->services->withMonitoredScope(
    Loom\Monitoring\MonitoringContext::console('queue-events'),
    static function (Loom\ServiceScope $scope): void {
        $scope->get(Loom\Events\EventDispatcher::class)->dispatch(new QueueEventsTestEvent(11));
        queueEventsCheck(QueueEventsSyncListener::$sync === 11, 'sync listener ran in dispatch');
        queueEventsCheck(QueueEventsQueuedListener::$queued === 0, 'queued listener not run inline');
        $claimed = $scope->get(Loom\Queue::class)->claim();
        queueEventsCheck($claimed !== null && $claimed['kind'] === 'loom.event.listen', 'queued listener job claimable');
        $payload = $claimed['payload'];
        queueEventsCheck(($payload['event'] ?? '') === QueueEventsTestEvent::class, 'event class in job payload');
        queueEventsCheck(($payload['listener'] ?? '') === QueueEventsQueuedListener::class, 'listener class in payload');
        $scope->get(Loom\Queue::class)->finish($claimed, '{"ok":true}', null);
        queueEventsCheck(QueueEventsQueuedListener::$queued === 11, 'queued listener ran in worker');
    },
);

$failBuilder = Loom\Application::createBuilder($root);
$failBuilder->services->addScoped(QueueEventsFailingListener::class);
$failBuilder->services->addScoped(QueueEventsQueuedListener::class);
$failBuilder->events
    ->listen(QueueEventsTestEvent::class, QueueEventsFailingListener::class)
    ->listen(QueueEventsTestEvent::class, QueueEventsQueuedListener::class, queued: true);
$failApp = $failBuilder->build();
QueueEventsQueuedListener::$queued = 0;
$aborted = false;
try {
    $failApp->services->withScope(static function (Loom\ServiceScope $scope): void {
        $scope->get(Loom\Events\EventDispatcher::class)->dispatch(new QueueEventsTestEvent(1));
    });
} catch (RuntimeException $error) {
    $aborted = $error->getMessage() === 'sync failed';
}
queueEventsCheck($aborted, 'sync failure propagates');
queueEventsCheck($failApp->services->withScope(static fn (Loom\ServiceScope $scope): ?array => $scope->get(Loom\Queue::class)->claim()) === null, 'queued listener not enqueued after sync failure');

$app->dispose();
$failApp->dispose();
rmdir($root . '/config');
rmdir($root);

echo "loom_queue_events_test: OK\n";
