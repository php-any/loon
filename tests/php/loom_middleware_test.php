<?php
require __DIR__ . '/../../runtime/framework/php/loom.php';
function check_middleware($ok) { if (!$ok) { throw new RuntimeException('middleware assertion'); } }
function trace_middleware($name) {
    return function ($request, $next) use ($name) {
        $GLOBALS['trace'][] = $name . ':in';
        $response = $next($request);
        $GLOBALS['trace'][] = $name . ':out';
        return $response->withHeader('X-' . $name, 'yes');
    };
}
class TestMiddleware implements Loom\Middleware {
    private string $value;
    public function __construct(string $value) { $this->value = $value; }
    public function handle(Loom\Request $request, callable $next): Loom\Response {
        check_middleware($request->parameter('id') === '7');
        return $next($request->withAttribute('user', $this->value));
    }
}
$app = new Loom\Application();
$GLOBALS['factories'] = 0;
$app->services()->bind(TestMiddleware::class, function ($services) {
    $GLOBALS['factories']++;
    return new TestMiddleware('Ada');
});
$app->group('/api', function ($app) {
    $app->group('/v1', function ($app) {
        $app->get('/items/{id}', function ($request) {
            $GLOBALS['trace'][] = 'action';
            return new Loom\Response($request->attribute('user'));
        }, 'item', [trace_middleware('route'), TestMiddleware::class]);
    }, [trace_middleware('inner')]);
}, [trace_middleware('outer')]);
// Globals registered after routes still wrap dispatch, including 404 and 405.
$app->middleware(trace_middleware('global'));
check_middleware($GLOBALS['factories'] === 0);
foreach (['GET', 'HEAD'] as $method) {
    $GLOBALS['trace'] = [];
    $request = new Loom\Request(['method' => $method, 'path' => '/api/v1/items/7']);
    $result = $app->handle($request)->toArray();
    check_middleware($result['body'] === 'Ada' && $result['headers']->{'x-global'} === 'yes');
    check_middleware($GLOBALS['trace'] === ['global:in', 'outer:in', 'inner:in', 'route:in', 'action', 'route:out', 'inner:out', 'outer:out', 'global:out']);
    check_middleware($request->attribute('user', 'none') === 'none');
}
check_middleware($GLOBALS['factories'] === 1);
foreach ([['GET', '/missing', 404], ['POST', '/api/v1/items/7', 405]] as $case) {
    $GLOBALS['trace'] = [];
    $result = $app->handle(new Loom\Request(['method' => $case[0], 'path' => $case[1]]))->toArray();
    check_middleware($result['status'] === $case[2]);
    check_middleware($GLOBALS['trace'] === ['global:in', 'global:out']);
}
try { $app->group('/failed', function ($app) { throw new RuntimeException('stop'); }, [trace_middleware('leaked')]); }
catch (RuntimeException $error) {}
$app->get('/root', function ($request) { return new Loom\Response('root'); });
$GLOBALS['trace'] = [];
check_middleware($app->handle(new Loom\Request(['method' => 'GET', 'path' => '/root']))->toArray()['body'] === 'root');
check_middleware($GLOBALS['trace'] === ['global:in', 'global:out']);
$blocked = new Loom\Application();
$blocked->get('/private', 'MustNotResolveController', '', [function ($request, $next) { return new Loom\Response('denied', 403); }, 'MustNotResolveMiddleware']);
check_middleware($blocked->handle(new Loom\Request(['method' => 'GET', 'path' => '/private']))->toArray()['status'] === 403);
$blocked->websocket('/socket', 'MustNotResolveController', '', [function ($request, $next) { throw new Loom\HttpError(401, 'Login required'); }]);
check_middleware($blocked->handle(new Loom\Request(['method' => 'GET', 'path' => '/socket']))->toArray()['status'] === 401);
$blocked->middleware(function ($request, $next) { throw new Loom\HttpError(429, 'Slow down'); });
check_middleware($blocked->handle(new Loom\Request(['method' => 'GET', 'path' => '/missing']))->toArray()['status'] === 429);
foreach ([null, 42, [], new stdClass(), ''] as $bad) {
    $thrown = false;
    try { $app->middleware($bad); } catch (LogicException $error) { $thrown = true; }
    check_middleware($thrown);
}
$badApp = new Loom\Application();
$badApp->middleware(function ($request, $next) { throw new RuntimeException('unexpected'); });
$thrown = false;
try { $badApp->handle(new Loom\Request(['method' => 'GET', 'path' => '/'])); }
catch (RuntimeException $error) { $thrown = $error->getMessage() === 'unexpected'; }
check_middleware($thrown);
$request = new Loom\Request([]);
check_middleware($request->withAttribute('null', null)->attribute('null', 'fallback') === null);
class CallableMiddleware {
    public function __invoke($request, $next) { return $next($request)->withHeader('X-Object', 'yes'); }
    public function process($request, $next) { return $next($request)->withHeader('X-Method', 'yes'); }
}
$forms = new Loom\Application();
$forms->middleware(new CallableMiddleware());
$forms->middleware([new CallableMiddleware(), 'process']);
$forms->get('/items/{id}', function ($request) { return new Loom\Response($request->attribute('user')); }, '', [new TestMiddleware('object')]);
$forms->route('HEAD', '/items/{id}', function ($request) { return new Loom\Response('head'); });
$result = $forms->handle(new Loom\Request(['method' => 'GET', 'path' => '/items/7']))->toArray();
check_middleware($result['body'] === 'object' && $result['headers']->{'x-object'} === 'yes' && $result['headers']->{'x-method'} === 'yes');
check_middleware($forms->handle(new Loom\Request(['method' => 'HEAD', 'path' => '/items/7']))->toArray()['body'] === 'head');
$forms->get('/error', function ($request) { throw new Loom\HttpError(422, 'Invalid'); });
$result = $forms->handle(new Loom\Request(['method' => 'GET', 'path' => '/error']))->toArray();
check_middleware($result['status'] === 422 && $result['headers']->{'x-object'} === 'yes');
// Fluent options compose without mutating the application or reusable builders.
$fluent = new Loom\Application();
$base = $fluent->prefix('/api')->withMiddleware(trace_middleware('outer'));
$inner = $base->prefix('/v1')->withMiddleware(trace_middleware('inner'), trace_middleware('route'));
$inner->group(function ($routes) {
    $routes->get('/items/{id}', function ($request) { return new Loom\Response($request->parameter('id')); }, 'fluent.item');
});
$base->get('/base', function ($request) { return new Loom\Response('base'); });
$fluent->get('/plain', function ($request) { return new Loom\Response('plain'); });
check_middleware($fluent->url('fluent.item', ['id' => '7']) === '/api/v1/items/7');
foreach ([['/api/v1/items/7', ['outer:in', 'inner:in', 'route:in', 'route:out', 'inner:out', 'outer:out']], ['/api/base', ['outer:in', 'outer:out']], ['/plain', []], ['/missing', []]] as $case) {
    $GLOBALS['trace'] = [];
    $fluent->handle(new Loom\Request(['method' => 'GET', 'path' => $case[0]]));
    check_middleware($GLOBALS['trace'] === $case[1]);
}
try { $inner->group(function ($routes) { throw new RuntimeException('stop'); }); }
catch (RuntimeException $error) {}
$fluent->get('/after', function ($request) { return new Loom\Response('after'); });
$GLOBALS['trace'] = [];
check_middleware($fluent->handle(new Loom\Request(['method' => 'GET', 'path' => '/after']))->toArray()['body'] === 'after');
check_middleware($GLOBALS['trace'] === []);
// Each HTTP helper and WebSocket shares the same scoped pipeline.
foreach (['get', 'post', 'put', 'patch', 'delete', 'head', 'options'] as $verb) {
    $base->$verb('/verbs', function ($request) { return new Loom\Response($request->method()); });
    $GLOBALS['trace'] = [];
    check_middleware($fluent->handle(new Loom\Request(['method' => strtoupper($verb), 'path' => '/api/verbs']))->toArray()['body'] === strtoupper($verb));
    check_middleware($GLOBALS['trace'] === ['outer:in', 'outer:out']);
}
$fluent->withMiddleware(function ($request, $next) { return new Loom\Response('blocked', 403); })
    ->websocket('/socket', 'NeverResolve');
check_middleware($fluent->handle(new Loom\Request(['method' => 'GET', 'path' => '/socket']))->toArray()['status'] === 403);
$thrown = false;
try { $base->withMiddleware(42)->get('/invalid', 'NeverResolve'); }
catch (LogicException $error) { $thrown = true; }
check_middleware($thrown);
check_middleware($fluent->handle(new Loom\Request(['method' => 'GET', 'path' => '/api/invalid']))->toArray()['status'] === 404);
echo "loom_middleware_test: OK\n";
