<?php
require __DIR__ . '/../../runtime/framework/php/loom.php';
function check_route($ok) { if (!$ok) { throw new RuntimeException('route assertion'); } }
$app = new Loom\Application();
$app->group('/api', function ($r) {
    $r->group('/v1', function ($r) {
        $r->get('/items/{id}', function ($request) { return Loom\Response::json(['id' => $request->parameter('id')]); }, 'item');
        $r->patch('/items/{id}', function ($request) { return Loom\Response::json(['updated' => true]); });
    });
});
check_route($app->url('item', ['id' => 'hello world']) === '/api/v1/items/hello%20world');
$result = $app->handle(new Loom\Request(['method' => 'GET', 'path' => '/api/v1/items/7']))->toArray();
check_route($result['status'] === 200 && json_decode($result['body'], true)['id'] === '7');
try { $app->group('/failed', function ($r) { throw new RuntimeException('stop'); }); } catch (RuntimeException $e) {}
$app->get('/root', function ($r) { return Loom\Response::json(['ok' => true]); }, 'root');
check_route($app->url('root') === '/root');
foreach ([[], ['id' => 'a/b'], ['id' => ''], ['id' => 'a', 'extra' => 'b']] as $params) {
    $thrown = false;
    try { $app->url('item', $params); } catch (LogicException $e) { $thrown = true; }
    check_route($thrown);
}
$thrown = false;
try { $app->get('/another', function ($r) { return Loom\Response::json([]); }, 'root'); } catch (LogicException $e) { $thrown = true; }
check_route($thrown);
class RouteMethodsController {
    private string $prefix;
    public function __construct(string $prefix) { $this->prefix = $prefix; }
    public function show(Loom\Request $request): Loom\Response { return new Loom\Response($this->prefix . $request->parameter('id')); }
    public function store(Loom\Request $request): Loom\Response { return new Loom\Response('created', 201); }
    private function hidden(Loom\Request $request): Loom\Response { return new Loom\Response('private'); }
}
class RouteInvokableController {
    public function __invoke(Loom\Request $request): Loom\Response { return new Loom\Response('invokable'); }
}
$methods = new Loom\Application();
$GLOBALS['route_factory_calls'] = 0;
$methods->services()->bind(RouteMethodsController::class, function ($container) {
    $GLOBALS['route_factory_calls']++;
    return new RouteMethodsController('item:');
});
$methods->get('/items/{id}', [RouteMethodsController::class, 'show'], 'show');
$methods->post('/items', [RouteMethodsController::class, 'store']);
$methods->get('/object/{id}', [new RouteMethodsController('object:'), 'show']);
$methods->get('/invoke', RouteInvokableController::class);
check_route($GLOBALS['route_factory_calls'] === 0);
foreach ([['GET', '/items/7', 200, 'item:7'], ['POST', '/items', 201, 'created'], ['GET', '/object/9', 200, 'object:9'], ['GET', '/invoke', 200, 'invokable']] as $case) {
    $result = $methods->handle(new Loom\Request(['method' => $case[0], 'path' => $case[1]]))->toArray();
    check_route($result['status'] === $case[2] && $result['body'] === $case[3]);
}
check_route($GLOBALS['route_factory_calls'] === 1);
check_route($methods->url('show', ['id' => '42']) === '/items/42');
foreach (['missing', 'hidden'] as $method) {
    $methods->get('/bad/' . $method, [RouteMethodsController::class, $method]);
    $thrown = false;
    try { $methods->handle(new Loom\Request(['method' => 'GET', 'path' => '/bad/' . $method])); }
    catch (LogicException $e) { $thrown = true; }
    check_route($thrown);
}
foreach ([[], [RouteMethodsController::class], [RouteMethodsController::class, ''], [42, 'show'], ['controller' => RouteMethodsController::class, 'method' => 'show']] as $bad) {
    foreach (['get', 'websocket'] as $register) {
        $thrown = false;
        try { $methods->$register('/invalid', $bad); } catch (LogicException $e) { $thrown = true; }
        check_route($thrown);
    }
}
echo "loom_routes_test: OK\n";
