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
file_put_contents($root . '/config/modules.php', '<?php return [MetadataFixtureModule::class];');
file_put_contents($root . '/components/Endpoint.php', '<?php namespace MetadataFixture; #[\Loom\Attributes\Controller] final class Endpoint { #[\Loom\Attributes\Route("GET", "/cached")] public function index(): \Loom\Response { return \Loom\Response::text("cached"); } }');
require $root . '/components/Endpoint.php';
$scope = new \Loom\ServiceScope(new \Loom\Config($root));
$first = $scope->fork();
$second = $scope->fork();
try {
    $discovery = $first->container()->get(\Loom\ModuleDiscovery::class);
    $snapshot = $discovery->configured();
    // Any rescan would try to reflect a missing class and fail.
    file_put_contents($root . '/components/MustNotBeScanned.php', '<?php throw new RuntimeException("unexpected rescan");');
    metadataCheck($second->container()->get(\Loom\ModuleDiscovery::class) === $discovery, 'Metadata owner is shared across request scopes');
    metadataCheck($discovery->configured() === $snapshot, 'Configured metadata is reused');
    $entry = dirname(__DIR__) . '/bootstrap/http.php';
    $one = $first->load($entry);
    $two = $second->load($entry);
    metadataCheck($one->router() !== $two->router(), 'Request route tables are independent');
    $one->get('/local', static fn ($request) => \Loom\Response::text('local'));
    metadataCheck($two->router()->match('GET', '/local')->status === 404, 'Request additions never mutate cached routes');
    metadataCheck($two->router()->match('GET', '/cached')->status === 200, 'Cached declaration remains available');
    metadataCheck($first->container()->get(\MetadataFixture\Endpoint::class) !== $second->container()->get(\MetadataFixture\Endpoint::class), 'Controllers remain scoped');
    $rejected = false;
    try {
        $snapshot->register('MissingComponent');
    } catch (LogicException $error) {
        $rejected = true;
    }
    metadataCheck($rejected, 'Shared snapshot cannot be mutated');
    $rejected = false;
    try {
        (new \Loom\ModuleDiscovery(new \Loom\Config($root)))->register(MutableMetadataEndpoint::class);
    } catch (LogicException $error) {
        $rejected = str_contains($error->getMessage(), 'Attribute middleware');
    }
    metadataCheck($rejected, 'Cached metadata must not retain mutable middleware objects');
    $isolated = new \Loom\ServiceScope(new \Loom\Config($root . '/empty'));
    try {
        metadataCheck($isolated->container()->get(\Loom\ModuleDiscovery::class)->configured()->routes() === [], 'Different applications do not share metadata');
    } finally {
        $isolated->close();
    }
} finally {
    $first->close();
    $second->close();
    $scope->close();
    unlink($root . '/components/MustNotBeScanned.php');
    unlink($root . '/components/Endpoint.php');
    unlink($root . '/config/modules.php');
    rmdir($root . '/components');
    rmdir($root . '/config');
    rmdir($root . '/empty');
    rmdir($root);
}
echo "loom_module_cache_test: OK\n";
