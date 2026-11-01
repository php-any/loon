<?php

require_once dirname(__DIR__) . '/bootstrap.php';
$runtimeScope = new \Loom\ServiceScope(new \Loom\Config());
$runtimeServices = $runtimeScope->services();

/**
 * 断言测试条件成立，失败时立即抛出异常以终止当前测试。
 *
 * @param mixed $condition 待断言的条件；为假时抛出测试失败异常。
 * @param mixed $message 断言失败时抛出的说明。
 * @return void 无返回值；条件失败时抛出 RuntimeException。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example loom_check(true, '验证说明');
 */
function loom_check($condition, $message)
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/**
 * 无状态服务夹具，用于验证容器实例解析与复用。
 *
 * 构造参数：无，本类型未声明构造参数。
 *
 * 返回值：使用 new 构造时得到 LoomTestService 实例；注册到容器时由容器管理实例的创建与复用。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $instance = $services->get(LoomTestService::class); // 在对应测试环境中解析；无效夹具用于断言异常
 */
class LoomTestService
{
}
$container = (new \Loom\ServiceScope($runtimeServices->get(\Loom\Config::class)))->services();
$calls = 0;
$container->bind(LoomTestService::class, function ($container) use (&$calls, $runtimeServices) {
    $calls++;
    return new LoomTestService();
});
loom_check($container->get(LoomTestService::class) === $container->get(LoomTestService::class), 'service identity');
loom_check($calls === 1, 'factory runs once');
$container->bind('retry', function ($container) use (&$calls, $runtimeServices) {
    if ($calls++ === 1) {
        throw new RuntimeException('retry');
    }
    return new LoomTestService();
});
try {
    $container->get('retry');
} catch (RuntimeException $error) {
}
loom_check($container->get('retry') instanceof LoomTestService, 'failed resolution cleans cycle state');
$container->bind('cycle.a', function ($services) use ($runtimeServices) {
    return $services->get('cycle.b');
});
$container->bind('cycle.b', function ($services) use ($runtimeServices) {
    return $services->get('cycle.a');
});
$cycle = false;
try {
    $container->get('cycle.a');
} catch (LogicException $error) {
    $cycle = str_contains($error->getMessage(), 'Dependency cycle');
}
loom_check($cycle, 'native container cycle error preserves Loom contract');
loom_check($container->get('retry') instanceof LoomTestService, 'native resolution stack clears after cycle');
loom_check(((new \Loom\ServiceScope($runtimeServices->get(\Loom\Config::class)))->services())->get(LoomTestService::class) !== $container->get(LoomTestService::class), 'native containers isolate shared instances');

/**
 * 读取路由 id 并输出 JSON，验证 GET 路由参数绑定。
 *
 * 构造参数：无，本类型未声明构造参数。
 *
 * 返回值：使用 new 构造时得到 LoomTestGet 实例；注册到容器时由容器管理实例的创建与复用。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $instance = $services->get(LoomTestGet::class); // 在对应测试环境中解析；无效夹具用于断言异常
 */
class LoomTestGet implements Loom\Action
{
    /**
     * 读取已绑定的 id 路由参数并生成 JSON。
     *
     * @param Loom\Request $request 当前 HTTP 请求；中间件修改属性时应传递请求副本。
     * @return Loom\Response 包含 id 字段的 JSON 响应。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $response = $handler($request->withParameters(['id' => '7']));
     */
    public function __invoke(Loom\Request $request): Loom\Response
    {
        return Loom\Response::json(['id' => $request->parameter('id')]);
    }
}
/**
 * 返回空正文和 202 状态，验证 HEAD 路由响应。
 *
 * 构造参数：无，本类型未声明构造参数。
 *
 * 返回值：使用 new 构造时得到 LoomTestHead 实例；注册到容器时由容器管理实例的创建与复用。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $instance = $services->get(LoomTestHead::class); // 在对应测试环境中解析；无效夹具用于断言异常
 */
class LoomTestHead implements Loom\Action
{
    /**
     * 构建用于 HEAD 路由测试的空响应。
     *
     * @param Loom\Request $request 当前 HTTP 请求；中间件修改属性时应传递请求副本。
     * @return Loom\Response 状态为 202、正文为空的响应。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $response = $handler($request);
     */
    public function __invoke(Loom\Request $request): Loom\Response
    {
        return new Loom\Response('', 202);
    }
}
$app = new Loom\Application();
$app->route('GET', '/items/{id}', LoomTestGet::class);
$app->route('HEAD', '/items/{id}', LoomTestHead::class);
$request = new Loom\Request(['method' => 'GET', 'path' => '/items/abc', 'body' => '', 'query' => [], 'headers' => []]);
loom_check($app->handle($request)->toArray()['body'] === '{"id":"abc"}', 'route parameter');
$missing = false;
try {
    $request->parameter('id');
} catch (LogicException $error) {
    $missing = true;
}
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
