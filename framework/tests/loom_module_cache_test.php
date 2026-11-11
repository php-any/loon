<?php

declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';

require __DIR__ . '/fixtures/module_cache.php';

function metadataCheck(bool $ok, string $message): void
{
    if (!$ok) {
        throw new RuntimeException($message);
    }
}

$root = sys_get_temp_dir() . '/loom-metadata-' . bin2hex(random_bytes(6));
mkdir($root . '/config', 0700, true);
mkdir($root . '/components', 0700, true);
mkdir($root . '/empty', 0700, true);
file_put_contents($root . '/components/Endpoint.php', '<?php namespace MetadataFixture; #[\Loom\Attributes\Controller] final class Endpoint { #[\Loom\Attributes\Route("GET", "/cached")] public function index(): \Loom\Response { return new \Loom\Response("cached"); } }');
require $root . '/components/Endpoint.php';
$app = null;
$isolated = null;
try {
    $app = Loom\Application::createBuilder($root)->addModule(MetadataFixtureModule::class)->build();
    $discovery = $app->services->get(Loom\ModuleDiscovery::class);
    // Any rescan would try to reflect a missing class and fail.
    file_put_contents($root . '/components/MustNotBeScanned.php', '<?php throw new RuntimeException("unexpected rescan");');
    $scoped = $app->services->withScope(static fn (Loom\ServiceScope $scope): array => [$scope->get(Loom\ModuleDiscovery::class), $scope->get(MetadataFixture\Endpoint::class)]);
    $other = $app->services->withScope(static fn (Loom\ServiceScope $scope): array => [$scope->get(Loom\ModuleDiscovery::class), $scope->get(MetadataFixture\Endpoint::class)]);
    metadataCheck($scoped[0] === $discovery && $other[0] === $discovery, 'Metadata owner is shared across request scopes');
    metadataCheck($scoped[1] !== $other[1], 'Controllers remain scoped');
    $one = $discovery->router();
    $two = $discovery->router();
    metadataCheck($one !== $two, 'Route tables handed out by the metadata are independent');
    $one->get('/local', static fn ($request) => new Loom\Response('local'));
    $app->mapGet('/app-local', static fn ($request) => new Loom\Response('app'));
    metadataCheck($two->match('GET', '/local')->status === 404 && $discovery->router()->match('GET', '/app-local')->status === 404, 'Additions never mutate cached routes');
    metadataCheck($two->match('GET', '/cached')->status === 200, 'Cached declaration remains available');
    foreach (['/cached' => 'cached', '/app-local' => 'app'] as $path => $body) {
        metadataCheck($app->handle(new Loom\Request(['method' => 'GET', 'path' => $path]))->toArray()['body'] === $body, 'Application serves declared and mapped routes without rescanning: ' . $path);
    }
    $rejected = false;
    try {
        $discovery->register('MissingComponent');
    } catch (LogicException $error) {
        $rejected = true;
    }
    metadataCheck($rejected, 'Shared snapshot cannot be mutated');
    $rejected = false;
    try {
        (new Loom\ModuleDiscovery(new Loom\Config($root)))->register(MutableMetadataEndpoint::class);
    } catch (LogicException $error) {
        $rejected = str_contains($error->getMessage(), 'Attribute middleware');
    }
    metadataCheck($rejected, 'Cached metadata must not retain mutable middleware objects');
    $isolated = Loom\Application::createBuilder($root . '/empty')->build();
    metadataCheck($isolated->services->get(Loom\ModuleDiscovery::class)->routes() === [], 'Different applications do not share metadata');
    metadataCheck($isolated->handle(new Loom\Request(['method' => 'GET', 'path' => '/cached']))->toArray()['status'] === 404, 'Other applications do not see cached routes');
} finally {
    if ($app !== null) {
        $app->dispose();
    }
    if ($isolated !== null) {
        $isolated->dispose();
    }
    if (is_file($root . '/components/MustNotBeScanned.php')) {
        unlink($root . '/components/MustNotBeScanned.php');
    }
    unlink($root . '/components/Endpoint.php');
    rmdir($root . '/components');
    rmdir($root . '/config');
    rmdir($root . '/empty');
    rmdir($root);
}
echo "loom_module_cache_test: OK\n";
