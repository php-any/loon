<?php
require __DIR__ . '/../../runtime/framework/php/loom.php';

function loom_check($condition, $message) {
    if (!$condition) { throw new RuntimeException($message); }
}

class LoomTestService {}
$container = new Loom\Container();
$calls = 0;
$container->bind(LoomTestService::class, function ($container) use (&$calls) {
    $calls++;
    return new LoomTestService();
});
loom_check($container->get(LoomTestService::class) === $container->get(LoomTestService::class), 'service identity');
loom_check($calls === 1, 'factory runs once');
$container->bind('retry', function ($container) use (&$calls) {
    if ($calls++ === 1) { throw new RuntimeException('retry'); }
    return new LoomTestService();
});
try { $container->get('retry'); } catch (RuntimeException $error) {}
loom_check($container->get('retry') instanceof LoomTestService, 'failed resolution cleans cycle state');

class LoomTestGet implements Loom\Action {
    public function __invoke(Loom\Request $request): Loom\Response {
        return Loom\Response::json(['id' => $request->parameter('id')]);
    }
}
class LoomTestHead implements Loom\Action {
    public function __invoke(Loom\Request $request): Loom\Response {
        return new Loom\Response('', 202);
    }
}
$app = new Loom\Application();
$app->route('GET', '/items/{id}', LoomTestGet::class);
$app->route('HEAD', '/items/{id}', LoomTestHead::class);
$request = new Loom\Request(['method' => 'GET', 'path' => '/items/abc', 'body' => '', 'query' => [], 'headers' => []]);
loom_check($app->handle($request)->toArray()['body'] === '{"id":"abc"}', 'route parameter');
$missing = false;
try { $request->parameter('id'); } catch (LogicException $error) { $missing = true; }
loom_check($missing, 'original request is unchanged');
$head = new Loom\Request(['method' => 'HEAD', 'path' => '/items/abc', 'body' => '', 'query' => [], 'headers' => []]);
loom_check($app->handle($head)->toArray()['status'] === 202, 'explicit HEAD wins');
$response = Loom\Response::json([]);
$changed = $response->withHeader('CONTENT-TYPE', 'application/custom');
loom_check($response->toArray()['headers']->{'content-type'} === 'application/json; charset=utf-8', 'immutable response');
loom_check($changed->toArray()['headers']->{'content-type'} === 'application/custom', 'case insensitive headers');
loom_check(Loom\escape('<a title="x">') === '&lt;a title=&quot;x&quot;&gt;', 'HTML escaping');
$bool = new Loom\Input(['enabled' => true]);
loom_check($bool->boolean('enabled') === true, 'boolean contract');
echo "loom_framework_test: OK\n";
