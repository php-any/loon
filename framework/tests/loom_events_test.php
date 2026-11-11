<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

function eventsCheck(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

final class EventsTestPaid
{
    public function __construct(public int $orderId)
    {
    }
}

final class EventsTestOther
{
    public function __construct(public string $label)
    {
    }
}

final class EventsTestReceipt
{
    public static int $count = 0;

    public function handle(EventsTestPaid $event): void
    {
        self::$count++;
    }
}

final class EventsTestFail
{
    public function handle(EventsTestPaid $event): void
    {
        throw new RuntimeException('listener failed');
    }
}

final class EventsTestNested
{
    public function __construct(private readonly Loom\Events\EventDispatcher $events)
    {
    }

    public function handle(EventsTestPaid $event): void
    {
        $this->events->dispatch(new EventsTestOther('nested'));
    }
}

final class EventsTestReentrant
{
    public function __construct(private readonly Loom\Events\EventDispatcher $events)
    {
    }

    public function handle(EventsTestPaid $event): void
    {
        $this->events->dispatch(new EventsTestPaid(99));
    }
}

$root = sys_get_temp_dir() . '/loom-events-' . bin2hex(random_bytes(6));
mkdir($root . '/config', 0700, true);
file_put_contents($root . '/config/modules.php', '<?php return [];');

$lines = [];
$builder = Loom\Application::createBuilder($root);
$closureHits = 0;
$builder->events->listen(EventsTestPaid::class, EventsTestReceipt::class);
$duplicate = false;
try {
    $builder->events->listen(EventsTestPaid::class, EventsTestReceipt::class);
} catch (LogicException) {
    $duplicate = true;
}
eventsCheck($duplicate, 'duplicate class registration rejected');
$builder->events->listen(EventsTestPaid::class, static function (EventsTestPaid $event) use (&$closureHits): void {
    $closureHits = $event->orderId;
});
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

try {
    EventsTestReceipt::$count = 0;
    $app = $builder->build();

    $app->services->withMonitoredScope(
        Loom\Monitoring\MonitoringContext::console('events-test'),
        static function (Loom\ServiceScope $scope) use (&$lines, &$closureHits): void {
            $scope->get(Loom\Events\EventDispatcher::class)->dispatch(new EventsTestPaid(42));
            eventsCheck(EventsTestReceipt::$count === 1, 'class listener invoked');
            eventsCheck($closureHits === 42, 'closure listener invoked');
            eventsCheck(count($lines) >= 1, 'monitoring session written');
            $session = json_decode($lines[count($lines) - 1], true, 64, JSON_THROW_ON_ERROR);
            $appEvents = array_values(array_filter(
                $session['entries'] ?? [],
                static fn (array $entry): bool => ($entry['event'] ?? '') === 'app.event',
            ));
            eventsCheck($appEvents !== [] && ($appEvents[0]['name'] ?? '') === EventsTestPaid::class, 'app.event recorded');
        },
    );

    $failBuilder = Loom\Application::createBuilder($root);
    $failBuilder->events
        ->listen(EventsTestPaid::class, EventsTestFail::class)
        ->listen(EventsTestPaid::class, EventsTestReceipt::class);
    $failApp = $failBuilder->build();
    EventsTestReceipt::$count = 0;
    $aborted = false;
    try {
        $failApp->services->withScope(static function (Loom\ServiceScope $scope): void {
            $scope->get(Loom\Events\EventDispatcher::class)->dispatch(new EventsTestPaid(1));
        });
    } catch (RuntimeException $error) {
        $aborted = $error->getMessage() === 'listener failed';
    }
    eventsCheck($aborted, 'listener failure propagates');
    eventsCheck(EventsTestReceipt::$count === 0, 'later listener skipped after failure');
    $failApp->dispose();

    $nestedBuilder = Loom\Application::createBuilder($root);
    $nestedBuilder->services->addScoped(EventsTestNested::class);
    $nestedBuilder->events->listen(EventsTestPaid::class, EventsTestNested::class);
    $nestedApp = $nestedBuilder->build();
    $nestedApp->services->withScope(static function (Loom\ServiceScope $scope): void {
        $scope->get(Loom\Events\EventDispatcher::class)->dispatch(new EventsTestPaid(7));
    });
    $nestedApp->dispose();

    $reentrantBuilder = Loom\Application::createBuilder($root);
    $reentrantBuilder->services->addScoped(EventsTestReentrant::class);
    $reentrantBuilder->events->listen(EventsTestPaid::class, EventsTestReentrant::class);
    $reentrantApp = $reentrantBuilder->build();
    $reentrant = false;
    try {
        $reentrantApp->services->withScope(static function (Loom\ServiceScope $scope): void {
            $scope->get(Loom\Events\EventDispatcher::class)->dispatch(new EventsTestPaid(1));
        });
    } catch (LogicException $error) {
        $reentrant = str_contains($error->getMessage(), 'Re-entrant');
    }
    eventsCheck($reentrant, 'same-event reentrancy blocked');
    $reentrantApp->dispose();
    $app->dispose();
} finally {
    unlink($root . '/config/modules.php');
    rmdir($root . '/config');
    rmdir($root);
}

echo "loom_events_test: OK\n";
