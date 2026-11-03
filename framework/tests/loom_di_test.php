<?php

declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';

interface DiContract {}
class DiService implements DiContract {}
class DiDependency { public function __construct(public DiContract $service) {} }
class DiResource {}
require __DIR__ . '/fixtures/di.php';
function diCheck(bool $condition, string $message): void
{
    if (!$condition) { throw new RuntimeException($message); }
}
function diReject(callable $operation, string $message): void
{
    $rejected = false;
    try { $operation(); } catch (LogicException $error) { $rejected = str_contains($error->getMessage(), $message); }
    diCheck($rejected, 'Expected rejection: ' . $message);
}

$calls = 0;
$root = new Loom\ServiceScope(new Loom\Config(), function (Loom\Container $c) use (&$calls): void {
    $calls++;
    $c->singleton('shared', DiService::class);
    $c->scoped(DiContract::class, DiService::class);
    $c->transient('fresh', DiService::class);
    $c->alias('shared.alias', 'shared');
    $c->alias('scoped.alias', DiContract::class);
    $c->alias('fresh.alias', 'fresh');
    $c->singleton('singleton.resource', DiResource::class);
    $c->scoped('scoped.resource', DiResource::class);
    $c->transient('transient.resource', DiResource::class);
});
$a = $root->fork();
$b = $a->fork();
$c = $a->services();
$d = $b->services();
diCheck($calls === 1, 'Registration callback runs once');
diReject(static function () use ($root): void {
    new Loom\ServiceScope(new Loom\Config(), static function (): void {
    }, $root->services());
}, 'root scope');
diCheck($c->resolved('shared') === null, 'Singleton is lazy');
diCheck($c->resolvedLocal('shared') === null, 'Scope cleanup excludes singleton cache');
diCheck($c->get('shared') === $d->get('shared') && $c->get('shared') === $root->services()->get('shared.alias'), 'Singleton spans nested scopes');
diCheck($c->get(DiContract::class) === $c->get('scoped.alias') && $c->get(DiContract::class) !== $d->get(DiContract::class), 'Scoped identity');
diCheck($c->get('fresh') !== $c->get('fresh.alias') && $c->get('fresh') !== $d->get('fresh'), 'Transient identity');
diCheck($c->get(DiDependency::class)->service === $c->get(DiContract::class), 'Constructor uses current scope binding');
diCheck($c->get(DiDependency::class) !== $d->get(DiDependency::class), 'Unregistered concrete classes default to scoped');
diCheck($c->get(Loom\Container::class) === $c && $c->get(Loom\ServiceScope::class) === $a, 'Local host bindings');
$other = new Loom\ServiceScope(new Loom\Config());
$other->services()->singleton('shared', DiService::class);
diCheck($other->services()->get('shared') !== $c->get('shared'), 'Independent application singletons are isolated');
$c->singleton('bad.direct', fn ($services) => $services->get(DiContract::class));
$c->transient('bridge', fn ($services) => $services->get('scoped.alias'));
$c->singleton('bad.indirect', fn ($services) => $services->get('bridge'));
foreach (['bad.direct', 'bad.indirect'] as $id) {
    diReject(fn () => $c->get($id), 'Singleton cannot depend on scoped');
}
$c->singleton('good', fn ($services) => new DiService());
diCheck($c->get('good') === $d->get('good'), 'Failure does not poison singleton resolution');
$c->singleton('cycle.a', fn ($services) => $services->get('cycle.b'));
$c->singleton('cycle.b', fn ($services) => $services->get('cycle.a'));
diReject(fn () => $c->get('cycle.a'), 'Dependency cycle');
$c->alias('alias.a', 'alias.b'); $c->alias('alias.b', 'alias.a');
diReject(fn () => $c->get('alias.a'), 'Dependency cycle');
diReject(fn () => $c->scoped('shared'), 'Duplicate service');
$attempts = 0;
$c->singleton('retry', function () use (&$attempts) {
    if (++$attempts === 1) { throw new LogicException('retry failure'); }
    return new DiService();
});
diReject(fn () => $c->get('retry'), 'retry failure');
diCheck($c->get('retry') === $d->get('retry') && $attempts === 2, 'Failed singleton factories retry');
$c->scoped('invalid', fn () => 123);
diReject(fn () => $c->get('invalid'), 'must return an object');
$invalid = new Loom\Container();
$invalid->scoped(DiContract::class, DiResource::class);
diReject(fn () => $invalid->get(DiContract::class), 'must implement');
$singletonResource = $c->get('singleton.resource');
diCheck($root->services()->resolvedLocal('singleton.resource') === null, 'Root cleanup also excludes application singleton');
$scopedResource = $c->get('scoped.resource');
$transientResource = $c->get('transient.resource');
$a->close(); $a->close();
diCheck($singletonResource === $d->get('singleton.resource'), 'Child cleanup preserves singleton');
$b->close(); $root->close(); $root->close();
diCheck($c->get('shared') === $d->get('shared'), 'Scope cleanup does not replace application singleton');
// Race the first singleton resolution across independently created scopes.
$state = new stdClass();
$state->calls = 0;
$c->singleton('concurrent', function () use ($state) {
    $state->calls++;
    usleep(20000);
    return new DiService();
});
$workers = [];
for ($i = 0; $i < 12; $i++) {
    $worker = new stdClass();
    $worker->services = $root->fork()->services();
    $worker->result = null;
    $worker->error = null;
    $worker->done = false;
    $workers[] = $worker;
    \spawn(static function () use ($worker): void {
        try {
            $worker->result = $worker->services->get('concurrent');
            $worker->services->get(DiContract::class);
        } catch (Throwable $error) {
            $worker->error = $error->getMessage();
        } finally {
            $worker->done = true;
        }
    });
}
$deadline = microtime(true) + 5;
foreach ($workers as $worker) {
    while (!$worker->done && microtime(true) < $deadline) { usleep(1000); }
    diCheck($worker->done && $worker->error === null, 'Concurrent resolution completes without errors: ' . ($worker->error ?? 'timeout'));
    diCheck($worker->result === $c->get('concurrent'), 'Concurrent requests share one singleton');
}
diCheck($state->calls === 1, 'Concurrent first resolution constructs exactly once');
$root->services()->get(Loom\Config::class)->setRoot(dirname(__DIR__, 2));
$modules = $root->services()->get(Loom\ModuleRegistry::class);
foreach ([DiModuleSingleton::class, DiModuleScoped::class, DiModuleTransient::class] as $class) { $modules->register($class); }
$modules->services($c);
$modules->services($d);
diCheck($c->get('di.module.shared') === $d->get('di.module.shared'), 'Module singleton uses shared registration');
diCheck($c->get('di.module.scoped') !== $d->get('di.module.scoped'), 'Module services default to scoped');
diCheck($c->get('di.module.fresh') !== $c->get('di.module.fresh'), 'Module transient registration');
diReject(fn () => new Loom\Attributes\Service('bad', 'invalid'), 'Invalid service lifetime');
foreach (['singleton', 'scoped', 'transient'] as $lifetime) {
    $bindings = new Loom\Container();
    $bindings->$lifetime(DiContract::class, DiService::class);
    $bindings->$lifetime(DiDependency::class);
    $child = $bindings->fork();
    $first = $bindings->get(DiDependency::class);
    diCheck($first->service instanceof DiContract, 'Interface constructor injection for ' . $lifetime);
    diCheck(($first === $bindings->get(DiDependency::class)) === ($lifetime !== 'transient'), 'Repeat identity for ' . $lifetime);
    diCheck(($first === $child->get(DiDependency::class)) === ($lifetime === 'singleton'), 'Cross-scope identity for ' . $lifetime);
}
echo "loom_di_test: OK\n";
