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
$app = new Loom\Application();
$app->group('/api', function ($r) {
    $r->group('/v1', function ($r) {
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
try {
    $app->group('/failed', function ($r) {
        throw new RuntimeException('stop');
    });
} catch (RuntimeException $e) {
}
$app->get('/root', function ($r) {
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
    $app->get('/another', function ($r) {
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
/**
 * 提供可调用控制器，验证 __invoke 路由解析。
 *
 * 构造参数：无，本类型未声明构造参数。
 *
 * 返回值：使用 new 构造时得到 RouteInvokableController 实例；注册到容器时由容器管理实例的创建与复用。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $instance = $services->get(RouteInvokableController::class); // 在对应测试环境中解析；无效夹具用于断言异常
 */
class RouteInvokableController
{
    /**
     * 提供无具名操作的控制器调用入口。
     *
     * @param Loom\Request $request 当前 HTTP 请求；中间件修改属性时应传递请求副本。
     * @return Loom\Response 正文 invokable 的响应。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $response = $controller($request);
     */
    public function __invoke(Loom\Request $request): Loom\Response
    {
        return new Loom\Response('invokable');
    }
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
    try {
        $methods->handle(new Loom\Request(['method' => 'GET', 'path' => '/bad/' . $method]));
    } catch (LogicException $e) {
        $thrown = true;
    }
    check_route($thrown);
}
foreach ([[], [RouteMethodsController::class], [RouteMethodsController::class, ''], [42, 'show'], ['controller' => RouteMethodsController::class, 'method' => 'show']] as $bad) {
    foreach (['get', 'websocket'] as $register) {
        $thrown = false;
        try {
            $methods->$register('/invalid', $bad);
        } catch (LogicException $e) {
            $thrown = true;
        }
        check_route($thrown);
    }
}
echo "loom_routes_test: OK\n";
