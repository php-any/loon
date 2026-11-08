<?php

declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';

function hostCheck(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function hostReject(callable $operation, string $message): void
{
    $rejected = false;
    try {
        $operation();
    } catch (Throwable $error) {
        $rejected = str_contains($error->getMessage(), $message);
    }
    hostCheck($rejected, 'Expected rejection: ' . $message);
}

final class HostingFlags
{
    public bool $enabled;
    public string $blank;
    public int $zero;
    public ?string $note;
    public string $kept = 'keep';
    public float $ratio;
}

final class HostingNested
{
    public string $label;
}

final class HostingRoot
{
    public HostingNested $nested;
    public int $count = 1;
}

final class HostingNeedsConstructor
{
    public function __construct(public string $value)
    {
    }
}

final class HostLog
{
    public array $events = [];
}

final class HostProbe implements Loom\HostedService
{
    public function __construct(private string $name, private HostLog $log, private ?Throwable $startError = null, private ?Throwable $stopError = null)
    {
    }

    public function start(): void
    {
        $this->log->events[] = 'start:' . $this->name;
        if ($this->startError !== null) {
            throw $this->startError;
        }
    }

    public function stop(): void
    {
        $this->log->events[] = 'stop:' . $this->name;
        if ($this->stopError !== null) {
            throw $this->stopError;
        }
    }
}

final class HostingScopedDependency
{
}

final class HostingCaptive implements Loom\HostedService
{
    public function __construct(public HostingScopedDependency $dependency)
    {
    }

    public function start(): void
    {
    }

    public function stop(): void
    {
    }
}

final class HostingCounter
{
    public int $value = 0;
}

final class HostingRequestState
{
    public static int $created = 0;
    public int $id;

    public function __construct()
    {
        $this->id = ++self::$created;
    }
}

final class HostingHeaderMiddleware implements Loom\Middleware
{
    public function __construct(private HostingRequestState $state)
    {
    }

    public function handle(Loom\Request $request, callable $next): Loom\Response
    {
        return $next($request)->withHeader('X-State', (string) $this->state->id);
    }
}

function hostProbes(HostLog $log, array $names, array $errors = []): Loom\Host
{
    $services = new Loom\ServiceCollection();
    foreach ($names as $name) {
        $services->addSingleton(Loom\HostedService::class, static fn (): HostProbe => new HostProbe($name, $log, $errors[$name]['start'] ?? null, $errors[$name]['stop'] ?? null));
    }
    $services->addSingleton(Loom\Host::class);
    return $services->build()->get(Loom\Host::class);
}

$root = sys_get_temp_dir() . '/loom-hosting-' . bin2hex(random_bytes(6));
mkdir($root . '/config', 0777, true);
try {
    file_put_contents($root . '/config/payment.php', '<?php return ["enabled"=>false,"blank"=>"","zero"=>0,"note"=>null,"ratio"=>1.5,"unused"=>true];');
    file_put_contents($root . '/config/tree.php', '<?php return ["nested"=>["label"=>"child"]];');
    file_put_contents($root . '/config/http.php', '<?php return ["address"=>"127.0.0.1:9"];');
    file_put_contents($root . '/config/scalar.php', '<?php return 5;');
    file_put_contents($root . '/config/modules.php', '<?php return [];');
    $config = new Loom\Config($root);
    $options = new Loom\Options($config);

    $flags = $options->bind(HostingFlags::class, 'payment');
    hostCheck($flags->enabled === false && $flags->blank === '' && $flags->zero === 0 && $flags->note === null, 'Options keep false, empty string, zero and null');
    hostCheck($flags->kept === 'keep' && $flags->ratio === 1.5, 'Missing key keeps its default and float is preserved');
    $tree = $options->bind(HostingRoot::class, 'tree');
    hostCheck($tree->nested instanceof HostingNested && $tree->nested->label === 'child' && $tree->count === 1, 'Nested options bind recursively');
    hostCheck($options->bind(Loom\HttpServerOptions::class, 'http')->address === '127.0.0.1:9', 'HTTP server options bind from the http section');
    hostReject(static fn () => $options->bind(HostingFlags::class, 'missing'), 'Missing configuration');
    hostReject(static fn () => $options->bind(HostingFlags::class, 'scalar'), 'must be an array');
    hostReject(static fn () => $options->bind(HostingNeedsConstructor::class, 'payment'), 'without required parameters');
    hostReject(static fn () => $options->bind(HostingNested::class, 'payment'), 'Missing option');

    $attempts = 0;
    $configured = new Loom\ServiceCollection();
    $configured->addInstance(Loom\Config::class, $config);
    $configured->configure(HostingFlags::class, 'payment', static function (HostingFlags $options) use (&$attempts): void {
        $attempts++;
        if ($attempts === 1) {
            throw new LogicException('validate later');
        }
    });
    $configuredProvider = $configured->build();
    hostReject(static fn () => $configuredProvider->get(HostingFlags::class), 'validate later');
    $bound = $configuredProvider->get(HostingFlags::class);
    hostCheck($configuredProvider->get(HostingFlags::class) === $bound && $attempts === 2, 'Failed options resolution can be retried and then is shared');

    $log = new HostLog();
    $host = hostProbes($log, ['a', 'b']);
    $host->start();
    $host->start();
    hostCheck($log->events === ['start:a', 'start:b'], 'Host starts hosted services once and in registration order');
    $host->stop();
    hostCheck($log->events === ['start:a', 'start:b', 'stop:b', 'stop:a'], 'Host stops in reverse order');
    $host->start();
    $host->stop();
    hostCheck(count($log->events) === 8, 'Host can start again after it stops');

    $rollback = new HostLog();
    $failing = hostProbes($rollback, ['a', 'b'], ['b' => ['start' => new LogicException('start failed')]]);
    hostReject(static fn () => $failing->start(), 'start failed');
    hostCheck($rollback->events === ['start:a', 'start:b', 'stop:a'], 'A failed start stops services that already started');
    $failing->stop();
    hostCheck($rollback->events === ['start:a', 'start:b', 'stop:a'], 'Stopping a host that failed to start does nothing');

    $partial = new HostLog();
    $stopping = hostProbes($partial, ['a', 'b'], ['b' => ['stop' => new LogicException('stop failed')]]);
    $stopping->start();
    hostReject(static fn () => $stopping->stop(), 'stop failed');
    hostCheck($partial->events === ['start:a', 'start:b', 'stop:b', 'stop:a'], 'A failed stop still stops the remaining services');

    $cycleLog = new HostLog();
    $value = hostProbes($cycleLog, ['a'])->run(static fn (): int => 7);
    hostCheck($value === 7 && $cycleLog->events === ['start:a', 'stop:a'], 'run starts, invokes the callback, then stops');

    $captive = new Loom\ServiceCollection();
    $captive->addHostedService(HostingCaptive::class);
    $captive->addSingleton(Loom\Host::class);
    $captiveHost = $captive->build()->get(Loom\Host::class);
    hostReject(static fn () => $captiveHost->start(), 'cannot depend on scoped');

    $builder = Loom\Application::createBuilder($root);
    $builder->services->addSingleton(HostingCounter::class);
    $builder->services->addScoped(HostingRequestState::class);
    $app = $builder->build();
    hostReject(static fn () => $builder->build(), 'already been built');
    hostCheck($app->configuration === $builder->configuration, 'Application exposes configuration');
    hostCheck($app->services->get(Loom\Config::class) === $builder->configuration, 'Config is registered as an instance');
    hostCheck($app->services->get(Loom\HttpServerOptions::class)->address === '127.0.0.1:9', 'HTTP options are configured by default');
    hostCheck($app->services->get(Loom\Host::class) === $app->services->get(Loom\Host::class), 'Host is a singleton');
    hostReject(static fn () => $app->services->get(Loom\Database::class), 'from the root provider');

    $app->use(HostingHeaderMiddleware::class);
    $app->use(static fn (Loom\Request $request, callable $next): Loom\Response => $next($request)->withHeader('X-Closure', 'yes'));
    $app->mapGet('/count', static function (Loom\Request $request, HostingCounter $counter, HostingRequestState $state, HostingRequestState $again): Loom\Response {
        $counter->value++;
        return Loom\Response::json(['count' => $counter->value, 'same' => $state === $again, 'state' => $state->id]);
    }, 'count');
    $app->mapPost('/items/{id}', static fn (Loom\Request $request): Loom\Response => Loom\Response::json(['id' => $request->parameter('id')]));
    $app->mapGroup('/api')->get('/ping', static fn (): Loom\Response => Loom\Response::json(['pong' => true]));
    hostCheck($app->url('count') === '/count', 'Named routes generate URLs');

    $first = $app->handle(new Loom\Request(['method' => 'GET', 'path' => '/count']))->toArray();
    $second = $app->handle(new Loom\Request(['method' => 'GET', 'path' => '/count']))->toArray();
    $firstBody = json_decode($first['body'], true);
    $secondBody = json_decode($second['body'], true);
    hostCheck($first['status'] === 200 && $firstBody['count'] === 1 && $secondBody['count'] === 2, 'Singletons persist across requests');
    hostCheck($firstBody['same'] === true && $firstBody['state'] !== $secondBody['state'], 'Scoped services are shared within a request and fresh per request');
    $headers = (array) $first['headers'];
    hostCheck(($headers['x-state'] ?? null) === (string) $firstBody['state'] && ($headers['x-closure'] ?? null) === 'yes', 'Global middleware resolves from the request scope');
    $item = $app->handle(new Loom\Request(['method' => 'POST', 'path' => '/items/42']))->toArray();
    hostCheck(json_decode($item['body'], true) === ['id' => '42'], 'Route parameters reach closure handlers');
    hostCheck(json_decode($app->handle(new Loom\Request(['method' => 'GET', 'path' => '/api/ping']))->toArray()['body'], true) === ['pong' => true], 'mapGroup prefixes routes');
    hostCheck($app->handle(new Loom\Request(['method' => 'GET', 'path' => '/missing']))->toArray()['status'] === 404, 'Unknown routes return 404');
    hostCheck($app->handle(new Loom\Request(['method' => 'DELETE', 'path' => '/count']))->toArray()['status'] === 405, 'Wrong methods return 405');

    $app->mapCommand('hosting:echo {word}', static function (Loom\Console\Input $input, Loom\Console\Output $output, HostingCounter $counter, Loom\ServiceScope $scope): int {
        $counter->value += 10;
        return $scope->get(HostingRequestState::class) === $scope->get(HostingRequestState::class) && $input->argument('word') === 'hi' ? 3 : 4;
    });
    hostCheck($app->run(['hosting:echo', 'hi']) === 3, 'Commands run in a scope with bound parameters');
    hostCheck($app->services->get(HostingCounter::class)->value === 12, 'Commands share application singletons');
    hostCheck($app->run(['help', 'serve']) === 0, 'Built-in commands are registered');
    $app->dispose();
    hostReject(static fn () => $app->services->get(HostingCounter::class), 'disposed');
} finally {
    foreach (glob($root . '/config/*') as $file) {
        unlink($file);
    }
    rmdir($root . '/config');
    rmdir($root);
}
echo "loom_hosting_test: OK\n";
