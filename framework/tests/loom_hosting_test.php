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

final class HostingFreshService implements Loom\HostedService
{
    public int $starts = 0;
    public int $stops = 0;

    public function start(): void
    {
        $this->starts++;
    }

    public function stop(): void
    {
        $this->stops++;
    }
}

final class HostingScopedService implements Loom\HostedService
{
    public function start(): void
    {
    }

    public function stop(): void
    {
    }
}

final class HostingCaptive implements Loom\HostedService
{
    public function __construct(public HostingScopedService $dependency)
    {
    }

    public function start(): void
    {
    }

    public function stop(): void
    {
    }
}

$root = sys_get_temp_dir() . '/loom-hosting-' . bin2hex(random_bytes(6));
mkdir($root . '/config', 0777, true);
try {
    file_put_contents($root . '/config/payment.php', '<?php return ["enabled"=>false,"blank"=>"","zero"=>0,"note"=>null,"ratio"=>1.5,"unused"=>true];');
    file_put_contents($root . '/config/tree.php', '<?php return ["nested"=>["label"=>"child"]];');
    file_put_contents($root . '/config/http.php', '<?php return ["address"=>"127.0.0.1:9","entry"=>"framework/bootstrap/http.php"];');
    file_put_contents($root . '/config/services.php', '<?php return static function (): void {};');
    $config = new Loom\Config($root);
    $scope = new Loom\ServiceScope($config);
    $services = $scope->services();
    $child = $scope->fork()->services();
    hostCheck($services->get(Loom\Options::class) === $child->get(Loom\Options::class), 'Options is an application singleton');
    hostCheck($services->get(Loom\Host::class) === $child->get(Loom\Host::class), 'Host is an application singleton');

    $flags = $services->get(Loom\Options::class)->bind(HostingFlags::class, 'payment');
    hostCheck($flags->enabled === false && $flags->blank === '' && $flags->zero === 0 && $flags->note === null, 'Options keep false, empty string, zero and null');
    hostCheck($flags->kept === 'keep' && $flags->ratio === 1.5, 'Missing key keeps its default and float is preserved');
    $tree = $services->get(Loom\Options::class)->bind(HostingRoot::class, 'tree');
    hostCheck($tree->nested instanceof HostingNested && $tree->nested->label === 'child' && $tree->count === 1, 'Nested options bind recursively');
    $http = $services->get(Loom\Options::class)->bind(Loom\HttpServerOptions::class, 'http');
    hostCheck($http->address === '127.0.0.1:9' && $http->entry === 'framework/bootstrap/http.php', 'HTTP server options bind from the http section');
    hostReject(static fn () => $services->get(Loom\Options::class)->bind(HostingFlags::class, 'missing'), 'Missing configuration');
    hostReject(static fn () => $services->get(Loom\Options::class)->bind(HostingFlags::class, 'services'), 'must be an array');
    hostReject(static fn () => $services->get(Loom\Options::class)->bind(HostingNeedsConstructor::class, 'payment'), 'without required parameters');
    hostReject(static fn () => $services->get(Loom\Options::class)->bind(HostingNested::class, 'payment'), 'Missing option');
    file_put_contents($root . '/config/payment.php', '<?php return ["enabled"=>1,"blank"=>"","zero"=>0,"note"=>null,"ratio"=>1.5];');
    $config->setRoot($root);
    hostReject(static fn () => $services->get(Loom\Options::class)->bind(HostingFlags::class, 'payment'), 'wrong type');

    file_put_contents($root . '/config/payment.php', '<?php return ["enabled"=>false,"blank"=>"","zero"=>0,"note"=>null,"ratio"=>1.5];');
    $config->setRoot($root);
    $attempts = 0;
    $services->options(HostingFlags::class, 'payment', static function (HostingFlags $options) use (&$attempts): void {
        $attempts++;
        if ($attempts === 1) {
            throw new LogicException('validate later');
        }
        hostCheck($options->zero === 0, 'Validator receives the bound options');
    });
    hostReject(static fn () => $services->get(HostingFlags::class), 'validate later');
    $bound = $services->get(HostingFlags::class);
    hostCheck($child->get(HostingFlags::class) === $bound && $attempts === 2, 'Failed options resolution can be retried and then is shared');
    file_put_contents($root . '/config/payment.php', '<?php return ["enabled"=>true,"blank"=>"x","zero"=>4,"note"=>"n","ratio"=>2.5];');
    $config->setRoot($root);
    hostCheck($services->get(HostingFlags::class)->zero === 0, 'Registered options keep the first successful snapshot');
    hostCheck($services->get(Loom\Options::class)->bind(HostingFlags::class, 'payment')->zero === 4, 'Direct bind reads the current configuration');

    $log = new HostLog();
    $host = new Loom\Host($services);
    $host->service(new HostProbe('a', $log));
    $host->service(new HostProbe('b', $log));
    $host->start();
    $host->start();
    hostReject(static fn () => $host->service(new HostProbe('c', $log)), 'after the host has started');
    hostCheck($log->events === ['start:a', 'start:b'], 'Host starts services once and in registration order');
    $host->stop();
    hostCheck($log->events === ['start:a', 'start:b', 'stop:b', 'stop:a'], 'Host stops in reverse order');
    $host->start();
    hostCheck($log->events === ['start:a', 'start:b', 'stop:b', 'stop:a', 'start:a', 'start:b'], 'Host can start again after it stops');
    $host->stop();

    $rollback = new HostLog();
    $failing = new Loom\Host($services);
    $failing->service(new HostProbe('a', $rollback));
    $failing->service(new HostProbe('b', $rollback, new LogicException('start failed')));
    hostReject(static fn () => $failing->start(), 'start failed');
    hostCheck($rollback->events === ['start:a', 'start:b', 'stop:a'], 'A failed start stops services that already started');
    $failing->stop();
    hostCheck($rollback->events === ['start:a', 'start:b', 'stop:a'], 'Stopping a host that failed to start does nothing');

    $partial = new HostLog();
    $stopping = new Loom\Host($services);
    $stopping->service(new HostProbe('a', $partial));
    $stopping->service(new HostProbe('b', $partial, null, new LogicException('stop failed')));
    $stopping->start();
    hostReject(static fn () => $stopping->stop(), 'stop failed');
    hostCheck($partial->events === ['start:a', 'start:b', 'stop:b', 'stop:a'], 'A failed stop still stops the remaining services');

    $ran = false;
    $cycle = new Loom\Host($services);
    $cycle->service(new HostProbe('a', $log));
    $value = $cycle->run(static function () use (&$ran) {
        $ran = true;
        return 7;
    });
    hostCheck($ran && $value === 7 && str_ends_with(implode(',', $log->events), 'start:a,stop:a'), 'run starts, invokes the callback, then stops');

    $scopedHost = new Loom\Host($services);
    $services->scoped(HostingScopedService::class);
    $scopedHost->service(HostingScopedService::class);
    hostReject(static fn () => $scopedHost->start(), 'must be a singleton');

    $captive = new Loom\Host($services);
    $captive->service(HostingCaptive::class);
    hostReject(static fn () => $captive->start(), 'Singleton cannot depend on scoped');

    $freshHost = new Loom\Host($services);
    $freshHost->service(HostingFreshService::class);
    $freshHost->start();
    $fresh = $services->get(HostingFreshService::class);
    hostCheck($fresh === $child->get(HostingFreshService::class) && $fresh->starts === 1, 'Unregistered hosted service becomes a shared singleton');
    hostCheck($services->lifetime(HostingFreshService::class) === 'singleton', 'Hosted service lifetime is singleton');
    $services->singleton('fresh.impl', static fn (): HostingFreshService => $fresh);
    $services->alias('fresh.alias', 'fresh.impl');
    hostCheck($services->lifetime('fresh.alias') === 'singleton', 'Alias lifetime follows its target');
    $freshHost->stop();
    hostCheck($fresh->stops === 1, 'Stopping the host stops the resolved singleton');
} finally {
    foreach (glob($root . '/config/*') as $file) {
        unlink($file);
    }
    rmdir($root . '/config');
    rmdir($root);
}
echo "loom_hosting_test: OK\n";
