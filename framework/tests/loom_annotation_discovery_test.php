<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

namespace AnnotationDiscoveryFixtures;

#[\Net\Annotation\Controller]
#[\Net\Annotation\Route('/api')]
#[\Net\Annotation\Middleware(\AnnotationDiscoveryFixtures\ProbeMiddleware::class)]
final class MappingController
{
    #[\Net\Annotation\GetMapping('/items')]
    public function list(): \Loom\Response
    {
        return new \Loom\Response('ok');
    }
}

final class ProbeMiddleware implements \Loom\Middleware
{
    public function handle(\Loom\Request $request, callable $next): \Loom\Response
    {
        return $next($request);
    }
}

final class MappingWithoutController
{
    #[\Net\Annotation\GetMapping('/orphan')]
    public function index(): void {}
}

#[\Net\Annotation\Controller]
final class HttpRouteController
{
    #[\Loom\Attributes\HttpRoute('PATCH', '/item', 'item.patch', [\AnnotationDiscoveryFixtures\ProbeMiddleware::class])]
    public function patch(): \Loom\Response
    {
        return new \Loom\Response('patch');
    }
}

#[\Net\Annotation\Controller]
final class ConflictController
{
    #[\Net\Annotation\GetMapping('/both')]
    #[\Loom\Attributes\HttpRoute('GET', '/both')]
    public function both(): \Loom\Response
    {
        return new \Loom\Response('');
    }
}

#[\Net\Annotation\Controller]
final class InvokeController
{
    #[\Net\Annotation\GetMapping('/invoke')]
    public function __invoke(): \Loom\Response
    {
        return new \Loom\Response('');
    }
}

#[\Net\Annotation\Controller]
final class SocketController
{
    #[\Loom\Attributes\HttpRoute('GET', '/socket', '', [], true)]
    public function socket(): void {}
}

#[\Container\Annotation\Singleton('probe.singleton')]
final class SingletonProbe {}

#[\Container\Annotation\Scoped('probe.scoped')]
final class ScopedProbe {}

#[\Container\Annotation\Component('probe.transient')]
final class TransientProbe {}

#[\Container\Annotation\Bind('probe.bound')]
final class BoundProbe {}

#[\Container\Annotation\Singleton]
#[\Container\Annotation\Scoped]
final class LifetimeConflict {}

#[\Cli\Annotation\Command]
final class CommandProbe extends \Loom\Console\Command
{
    protected string $signature = 'probe:discover';
    protected string $description = 'probe';

    public function handle(): int
    {
        return 0;
    }
}

final class InferredDependency {}

#[\Container\Annotation\Scoped('probe.inferred')]
final class InferredConsumer
{
    public function __construct(#[\Container\Annotation\Inject] InferredDependency $dependency) {}
}

namespace {
    /**
     * @param bool $condition
     * @param string $message
     */
    function checkAnnotationDiscovery(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }

    $config = new Loom\Config(dirname(__DIR__, 2));
    $discovery = new Loom\ModuleDiscovery($config);

    $reject = static function (callable $register, string $label): void {
        $failed = false;
        try {
            $register();
        } catch (LogicException $error) {
            $failed = true;
        }
        checkAnnotationDiscovery($failed, $label);
    };

    $discovery->register(\AnnotationDiscoveryFixtures\MappingController::class);
    $routes = $discovery->routes();
    checkAnnotationDiscovery(count($routes) === 1, 'single mapping route');
    checkAnnotationDiscovery($routes[0]['method'] === 'GET' && $routes[0]['path'] === '/api/items', 'class Route prefix join');
    checkAnnotationDiscovery($routes[0]['middleware'] === [\AnnotationDiscoveryFixtures\ProbeMiddleware::class], 'class middleware');

    $discovery->register(\AnnotationDiscoveryFixtures\HttpRouteController::class);
    $patch = $discovery->routes()[1];
    checkAnnotationDiscovery($patch['method'] === 'PATCH' && $patch['name'] === 'item.patch', 'HttpRoute patch and name');

    $discovery->register(\AnnotationDiscoveryFixtures\SocketController::class);
    checkAnnotationDiscovery($discovery->routes()[2]['websocket'] === true, 'HttpRoute websocket');

    $reject(static fn (): mixed => $discovery->register(\AnnotationDiscoveryFixtures\MappingWithoutController::class), 'mapping without Controller');
    $reject(static fn (): mixed => $discovery->register(\AnnotationDiscoveryFixtures\ConflictController::class), 'mapping conflicts with HttpRoute');
    $reject(static fn (): mixed => $discovery->register(\AnnotationDiscoveryFixtures\InvokeController::class), '__invoke route rejected');

    $discovery->register(\AnnotationDiscoveryFixtures\SingletonProbe::class);
    $discovery->register(\AnnotationDiscoveryFixtures\ScopedProbe::class);
    $discovery->register(\AnnotationDiscoveryFixtures\TransientProbe::class);
    $discovery->register(\AnnotationDiscoveryFixtures\BoundProbe::class);
    checkAnnotationDiscovery($discovery->serviceLifetime('probe.singleton') === 'singleton', 'Singleton lifetime');
    checkAnnotationDiscovery($discovery->serviceLifetime('probe.scoped') === 'scoped', 'Scoped lifetime');
    checkAnnotationDiscovery($discovery->serviceLifetime('probe.transient') === 'transient', 'Component maps to transient');
    checkAnnotationDiscovery($discovery->serviceLifetime('probe.bound') === 'scoped', 'Bind alone defaults scoped');
    $reject(static fn (): mixed => $discovery->register(\AnnotationDiscoveryFixtures\LifetimeConflict::class), 'conflicting lifetime markers');

    $discovery->register(\AnnotationDiscoveryFixtures\CommandProbe::class);
    checkAnnotationDiscovery(in_array(\AnnotationDiscoveryFixtures\CommandProbe::class, $discovery->commands(), true), 'Cli Command marker');

    $services = new Loom\ServiceCollection();
    $services->addScoped(\AnnotationDiscoveryFixtures\InferredDependency::class, static fn (): \AnnotationDiscoveryFixtures\InferredDependency => new \AnnotationDiscoveryFixtures\InferredDependency());
    $services->addScoped(\AnnotationDiscoveryFixtures\InferredConsumer::class, \AnnotationDiscoveryFixtures\InferredConsumer::class);
    $provider = $services->build();
    checkAnnotationDiscovery($provider->withScope(static fn (Loom\ServiceScope $scope): object => $scope->get(\AnnotationDiscoveryFixtures\InferredConsumer::class)) instanceof \AnnotationDiscoveryFixtures\InferredConsumer, 'empty Inject infers type');

    echo "loom_annotation_discovery_test: OK\n";
}
