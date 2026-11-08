<?php

declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';

interface DiContract {}
class DiService implements DiContract {}
class DiOther implements DiContract {}
class DiDependency { public function __construct(public DiContract $service) {} }
class DiNeedsScope { public function __construct(public Loom\ServiceScope $scope, public Loom\ServiceResolver $resolver, public Loom\ServiceProvider $root) {} }
class DiSingletonNeedsScoped { public function __construct(public DiContract $service) {} }
class DiCycleA { public function __construct(public DiCycleB $b) {} }
class DiCycleB { public function __construct(public DiCycleA $a) {} }
final class DiLog { public array $entries = []; }
final class DiTracked implements Loom\Disposable
{
    public function __construct(private DiLog $log, private string $name) {}
    public function dispose(): void { $this->log->entries[] = $this->name; }
}

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

$services = new Loom\ServiceCollection();
$services->addSingleton('shared', DiService::class);
$services->addScoped(DiContract::class, DiService::class);
$services->addTransient('fresh', DiService::class);
$services->addInstance('instance', $instance = new DiService());
$services->tryAddScoped(DiContract::class, DiOther::class);
$services->addSingleton(DiSingletonNeedsScoped::class);
$services->addSingleton('factory', static fn (Loom\ServiceResolver $resolver): DiService => new DiService());
$services->addSingleton('not.object', static fn (): string => 'x');
$services->addScoped('handlers', DiService::class);
$services->addScoped('handlers', DiOther::class);
$provider = $services->build();
diReject(static fn () => $services->addScoped('late', DiService::class), 'cannot be changed');
diReject(static fn () => $services->build(), 'cannot be changed');

diCheck($provider->get('shared') === $provider->get('shared'), 'Singleton is cached on the root');
diCheck($provider->get('instance') === $instance, 'Instance registration returns the object');
diCheck($provider->get('factory') instanceof DiService, 'Factory registration');
diReject(static fn () => $provider->get('not.object'), 'must return an object');
diReject(static fn () => $provider->get(DiContract::class), 'from the root provider');
diReject(static fn () => $provider->get(DiSingletonNeedsScoped::class), 'cannot depend on scoped');
diReject(static fn () => $provider->get('missing.id'), 'not registered');

$a = $provider->createScope();
$b = $provider->createScope();
diCheck($a->get(DiContract::class) instanceof DiService, 'tryAdd does not replace an existing registration');
diCheck($a->get(DiContract::class) === $a->get(DiContract::class), 'Scoped identity within a scope');
diCheck($a->get(DiContract::class) !== $b->get(DiContract::class), 'Scoped instances differ across scopes');
diCheck($a->get('shared') === $b->get('shared') && $a->get('shared') === $provider->get('shared'), 'Singleton spans scopes');
diCheck($a->get('fresh') !== $a->get('fresh'), 'Transient identity');
diCheck($a->get(DiDependency::class)->service === $a->get(DiContract::class), 'Constructor injection uses the current scope');
diCheck($a->get(DiDependency::class) === $a->get(DiDependency::class) && $a->get(DiDependency::class) !== $b->get(DiDependency::class), 'Unregistered classes are implicitly scoped');
$needs = $a->get(DiNeedsScope::class);
diCheck($needs->scope === $a && $needs->resolver === $a && $needs->root === $provider, 'Special service ids');
diCheck($a->get('handlers') instanceof DiOther, 'Last registration wins');
$all = $a->getAll('handlers');
diCheck(count($all) === 2 && $all[0] instanceof DiService && $all[1] instanceof DiOther, 'getAll returns every registration in order');
diCheck($a->getAll('none') === [] && $a->tryGet('none') === null && !$a->has('none'), 'Missing ids');
diReject(static fn () => $a->get(DiCycleA::class), 'Dependency cycle');
diReject(static fn () => $provider->get(Loom\ServiceScope::class), 'inside a scope');

$log = new DiLog();
$tracked = new Loom\ServiceCollection();
$tracked->addScoped('first', static fn (): DiTracked => new DiTracked($log, 'first'));
$tracked->addScoped('second', static function (Loom\ServiceResolver $resolver) use ($log): DiTracked {
    $resolver->get('first');
    return new DiTracked($log, 'second');
});
$tracked->addSingleton('root', static fn (): DiTracked => new DiTracked($log, 'root'));
$trackedProvider = $tracked->build();
$result = $trackedProvider->withScope(static function (Loom\ServiceScope $scope): string {
    $scope->get('second');
    $scope->get('root');
    return 'done';
});
diCheck($result === 'done', 'withScope returns the callback result');
diCheck($log->entries === ['second', 'first'], 'Scope disposes its services in reverse creation order, not singletons');
$failed = false;
try {
    $trackedProvider->withScope(static function (Loom\ServiceScope $scope): void {
        $scope->get('first');
        throw new RuntimeException('work failed');
    });
} catch (RuntimeException $error) {
    $failed = $error->getMessage() === 'work failed';
}
diCheck($failed && $log->entries === ['second', 'first', 'first'], 'Scope is disposed when the work fails');
$disposedScope = $trackedProvider->createScope();
$disposedScope->dispose();
diReject(static fn () => $disposedScope->get('first'), 'disposed');
$trackedProvider->dispose();
diCheck($log->entries === ['second', 'first', 'first', 'root'], 'Provider disposes singletons');
diReject(static fn () => $trackedProvider->get('root'), 'disposed');

$options = new Loom\ServiceCollection();
$root = sys_get_temp_dir() . '/loom-di-' . bin2hex(random_bytes(6));
mkdir($root . '/config', 0777, true);
file_put_contents($root . '/config/http.php', "<?php return ['address' => '127.0.0.1:1'];");
$options->addInstance(Loom\Config::class, new Loom\Config($root));
$options->configure(Loom\HttpServerOptions::class, 'http');
$hosted = new Loom\ServiceCollection();
diReject(static fn () => $hosted->addHostedService('Missing\\Hosted'), 'does not exist');
diCheck($options->build()->get(Loom\HttpServerOptions::class)->address === '127.0.0.1:1', 'configure binds an options section');
unlink($root . '/config/http.php');
rmdir($root . '/config');
rmdir($root);

$invoked = $provider->withScope(static function (Loom\ServiceScope $scope): array {
    $request = new Loom\Request(['method' => 'GET', 'path' => '/']);
    return Loom\Invoker::call(static fn (Loom\Request $r, DiContract $service, $first = 'none', ?DiCycleMissing $missing = null): array => [$r, $service, $first, $missing], $scope, [$request, 'positional']) + ['request' => $request, 'scoped' => $scope->get(DiContract::class)];
});
diCheck($invoked[0] === $invoked['request'], 'Invoker passes provided arguments by type');
diCheck($invoked[1] === $invoked['scoped'], 'Invoker resolves services from the scope');
diCheck($invoked[2] === 'positional' && $invoked[3] === null, 'Invoker binds untyped and nullable parameters');

echo "DI: OK\n";
