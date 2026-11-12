<?php

require_once dirname(__DIR__) . '/bootstrap.php';
/**
 * 断言测试条件成立，失败时立即抛出异常以终止当前测试。
 *
 * @param mixed $ok 待断言的条件；为假时抛出测试失败异常。
 * @return void 无返回值；条件失败时抛出 RuntimeException。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example check_route(true);
 */
function check_route($ok)
{
    if (!$ok) {
        throw new RuntimeException('route assertion');
    }
}
/**
 * 在给定项目根上构建应用；临时目录只创建 config/，不登记启动模块。
 *
 * @param ?string $root 项目根目录；null 表示使用一次性的临时目录。
 * @param ?callable $configure 构建前收到 ServiceCollection 的回调。
 * @return Loom\Application 已构建的应用。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $app = routes_app();
 */
function routes_app(?string $root = null, ?callable $configure = null): Loom\Application
{
    $temporary = $root === null;
    if ($temporary) {
        $root = sys_get_temp_dir() . '/loom-routes-' . bin2hex(random_bytes(6));
        mkdir($root . '/config', 0700, true);
    }
    try {
        $builder = Loom\Application::createBuilder($root);
        if ($configure !== null) {
            $configure($builder->services);
        }
        return $builder->build();
    } finally {
        if ($temporary) {
            rmdir($root . '/config');
            rmdir($root);
        }
    }
}
$app = routes_app();
$app->mapGroup('/api')->group(function (Loom\Router $r) {
    $r->group('/v1', function (Loom\Router $r) {
        $r->get('/items/{id}', function ($request) {
            return Loom\Response::json(['id' => $request->parameter('id')]);
        }, 'item');
        $r->patch('/items/{id}', function ($request) {
            return Loom\Response::json(['updated' => true]);
        });
    });
});
check_route($app->url('item', ['id' => 'hello world']) === '/api/v1/items/hello%20world');
$result = $app->handle(new Loom\Request(['method' => 'GET', 'path' => '/api/v1/items/7']))->toArray();
check_route($result['status'] === 200 && json_decode($result['body'], true)['id'] === '7');
check_route($app->handle(new Loom\Request(['method' => 'PATCH', 'path' => '/api/v1/items/7']))->toArray()['status'] === 200);
try {
    $app->mapGroup('/failed')->group(function ($r) {
        throw new RuntimeException('stop');
    });
} catch (RuntimeException $e) {
}
$app->mapGet('/root', function ($r) {
    return Loom\Response::json(['ok' => true]);
}, 'root');
check_route($app->url('root') === '/root');
foreach ([[], ['id' => 'a/b'], ['id' => ''], ['id' => 'a', 'extra' => 'b']] as $params) {
    $thrown = false;
    try {
        $app->url('item', $params);
    } catch (LogicException $e) {
        $thrown = true;
    }
    check_route($thrown);
}
$thrown = false;
try {
    $app->mapGet('/another', function ($r) {
        return Loom\Response::json([]);
    }, 'root');
} catch (LogicException $e) {
    $thrown = true;
}
check_route($thrown);
/**
 * 提供公开和私有操作，验证控制器方法路由及可见性校验。
 *
 * 构造参数：
 * - $prefix（string）：写入响应正文的测试前缀。
 *
 * 返回值：使用 new 构造时得到 RouteMethodsController 实例；注册到容器时由容器管理实例的创建与复用。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $instance = $services->get(RouteMethodsController::class); // 在对应测试环境中解析；无效夹具用于断言异常
 */
class RouteMethodsController
{
    private string $prefix;
    /**
     * 初始化 RouteMethodsController 实例及其依赖。提供公开和私有操作，验证控制器方法路由及可见性校验。
     *
     * @param string $prefix 写入响应正文的测试前缀。
     * @return void 无直接返回值；通过 new 表达式取得初始化后的实例。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $instance = $services->get(RouteMethodsController::class); // 在对应测试环境中解析；无效夹具用于断言异常
     */
    public function __construct(string $prefix)
    {
        $this->prefix = $prefix;
    }
    /**
     * 连接构造前缀与路由 id，验证容器构造及参数绑定。
     *
     * @param Loom\Request $request 当前 HTTP 请求；中间件修改属性时应传递请求副本。
     * @return Loom\Response 正文为前缀加 id 的响应。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $response = $controller->show($request->withParameters(['id' => '7']));
     */
    public function show(Loom\Request $request): Loom\Response
    {
        return new Loom\Response($this->prefix . $request->parameter('id'));
    }
    /**
     * 模拟成功创建资源。
     *
     * @param Loom\Request $request 当前 HTTP 请求；中间件修改属性时应传递请求副本。
     * @return Loom\Response 状态 201、正文 created 的响应。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $response = $controller->store($request);
     */
    public function store(Loom\Request $request): Loom\Response
    {
        return new Loom\Response('created', 201);
    }
    /**
     * 提供私有操作，验证路由不能公开调用私有方法。
     *
     * @param Loom\Request $request 当前 HTTP 请求；中间件修改属性时应传递请求副本。
     * @return Loom\Response 正文 private 的响应；路由注册应拒绝该方法。
     *
     * 内部调用示例（仅类内使用）：
     * @example $response = $this->hidden($request);
     */
    private function hidden(Loom\Request $request): Loom\Response
    {
        return new Loom\Response('private');
    }
}
$GLOBALS['route_factory_calls'] = 0;
$methods = routes_app(null, function (Loom\ServiceCollection $services) {
    $services->addScoped(RouteMethodsController::class, function () {
        $GLOBALS['route_factory_calls']++;
        return new RouteMethodsController('item:');
    });
});
$methods->mapGet('/items/{id}', [RouteMethodsController::class, 'show'], 'show');
$methods->mapPost('/items', [RouteMethodsController::class, 'store']);
$methods->mapGet('/object/{id}', [new RouteMethodsController('object:'), 'show']);
$thrown = false;
try {
    $methods->mapGet('/invoke', RouteMethodsController::class);
} catch (LogicException $e) {
    $thrown = true;
}
check_route($thrown);
check_route($GLOBALS['route_factory_calls'] === 0);
foreach ([['GET', '/items/7', 200, 'item:7'], ['POST', '/items', 201, 'created'], ['GET', '/object/9', 200, 'object:9']] as $case) {
    $result = $methods->handle(new Loom\Request(['method' => $case[0], 'path' => $case[1]]))->toArray();
    check_route($result['status'] === $case[2] && $result['body'] === $case[3]);
}
// 控制器在每次请求的作用域里解析一次；对象控制器不经过容器。
check_route($GLOBALS['route_factory_calls'] === 2);
check_route($methods->url('show', ['id' => '42']) === '/items/42');
foreach (['missing', 'hidden'] as $method) {
    $methods->mapGet('/bad/' . $method, [RouteMethodsController::class, $method]);
    $thrown = false;
    try {
        $methods->handle(new Loom\Request(['method' => 'GET', 'path' => '/bad/' . $method]));
    } catch (LogicException $e) {
        $thrown = true;
    }
    check_route($thrown);
}
foreach ([[], [RouteMethodsController::class], [RouteMethodsController::class, ''], [RouteMethodsController::class, '__invoke'], [42, 'show'], ['controller' => RouteMethodsController::class, 'method' => 'show']] as $bad) {
    foreach (['mapGet', 'mapWebSocket'] as $register) {
        $thrown = false;
        try {
            $methods->$register('/invalid', $bad);
        } catch (LogicException $e) {
            $thrown = true;
        }
        check_route($thrown);
    }
}
/**
 * 原样返回 RPC 输入，供协议分发测试断言数据一致性。
 *
 * 构造参数：无，本类型未声明构造参数。
 *
 * 返回值：使用 new 构造时得到 RouteFixtureRpc 实例；注册到容器时由容器管理实例的创建与复用。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $instance = $services->get(RouteFixtureRpc::class); // 在对应测试环境中解析；无效夹具用于断言异常
 */
final class RouteFixtureRpc
{
    /**
     * 原样返回传入 RPC 请求，验证分发过程不改变数据。
     *
     * @param array $request RPC 请求的数组数据，将原样返回。
     * @return array 与输入相同的请求数组。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $result = $rpc->echoValue(['value' => 7]);
     */
    public function echoValue(array $request): array
    {
        return $request;
    }
}
/**
 * 在新作用域里通过应用的 gRPC 方法表分发一次调用。
 *
 * @param Loom\Application $app 已构建的应用。
 * @param string $method 完整 RPC 路径。
 * @param array $request 解码后的请求。
 * @return array ['data' => ...] 或 ['error' => [...]]。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $result = route_rpc($app, '/fixture/echo', ['value' => 7]);
 */
function route_rpc(Loom\Application $app, string $method, array $request): array
{
    $dispatcher = $app->services->get(Loom\Grpc\Dispatcher::class);
    return $app->services->withScope(static fn (Loom\ServiceScope $scope): array => $dispatcher->handle($scope, $method, $request));
}
// 应用显式映射的 HTTP、WebSocket 和 gRPC 路由；旧版路由文件不得被隐式执行。
$root = sys_get_temp_dir() . '/loom-route-maps-' . bin2hex(random_bytes(6));
mkdir($root . '/routes', 0700, true);
mkdir($root . '/config', 0700, true);
mkdir($root . '/generated/grpc', 0700, true);
foreach (['web', 'websocket', 'grpc', 'console'] as $name) {
    file_put_contents($root . '/routes/' . $name . '.php', '<?php throw new RuntimeException("Implicit route file loaded");');
}
// 生成的 RPC 方法仍然可用；应用显式声明的映射具有更高优先级。
file_put_contents($root . '/generated/grpc/routes.json', json_encode(['files' => [['routes' => [
    '/fixture/generated' => [RouteFixtureRpc::class, 'echoValue'],
    '/fixture/echo' => ['MissingService', 'missing'],
]]]]));
try {
    $mapped = routes_app($root);
    $mapped->mapGet('/explicit', function ($request) {
        return new Loom\Response('explicit');
    });
    $mapped->mapWebSocket('/explicit-socket', static function (\Loom\Request $request, \Net\Websocket\Conn $connection): void {
    });
    $mapped->mapGrpc('/fixture/echo', [RouteFixtureRpc::class, 'echoValue']);
    $router = $mapped->services->get(Loom\Router::class);
    check_route($router->match('GET', '/explicit')->status === 200 && !$router->match('GET', '/explicit')->websocket);
    check_route($router->match('GET', '/explicit-socket')->websocket);
    check_route($mapped->handle(new Loom\Request(['method' => 'GET', 'path' => '/explicit']))->toArray()['body'] === 'explicit');
    foreach (['/fixture/echo', '/fixture/generated'] as $method) {
        check_route(route_rpc($mapped, $method, ['value' => 7]) === ['data' => ['value' => 7]]);
    }
    $empty = routes_app($root);
    check_route($empty->services->get(Loom\Router::class)->match('GET', '/explicit')->status === 404);
    check_route(route_rpc($empty, '/fixture/unknown', [])['error']['code'] === 'unimplemented');
    check_route(route_rpc($empty, '/fixture/generated', []) === ['data' => []]);
    $mapped->dispose();
    $empty->dispose();
} finally {
    foreach (['web', 'websocket', 'grpc', 'console'] as $name) {
        unlink($root . '/routes/' . $name . '.php');
    }
    unlink($root . '/generated/grpc/routes.json');
    rmdir($root . '/generated/grpc');
    rmdir($root . '/generated');
    rmdir($root . '/routes');
    rmdir($root . '/config');
    rmdir($root);
}
// 项目应用的注解路由与 gRPC 映射，不打开数据库连接或启动服务器。
$project = require dirname(__DIR__, 2) . '/loom.php';
try {
    $router = $project->services->get(Loom\Router::class);
    check_route($router->match('GET', '/demo')->status === 200);
    check_route($router->match('GET', '/api/admin/notifications/socket')->websocket);
    check_route(isset($project->services->get(Loom\Grpc\Dispatcher::class)->routes()['/loom.examples.ReleasePolicy/Evaluate']));
} finally {
    $project->dispose();
}
echo "loom_routes_test: OK\n";
