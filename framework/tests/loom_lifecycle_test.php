<?php

declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';

/**
 * 断言测试条件成立，失败时立即抛出异常以终止当前测试。
 *
 * @param bool $condition 待断言的条件；为假时抛出测试失败异常。
 * @param string $message 断言失败时抛出的说明。
 * @return void 无返回值；条件失败时抛出 RuntimeException。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example lifecycleCheck(true, '验证说明');
 */
function lifecycleCheck(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

// 匹配定义时绝不能解析控制器或中间件实例。
$router = new Loom\Router();
$router->get('/items/{id}', 'UnregisteredController', 'item', ['UnregisteredMiddleware']);
$match = $router->match('HEAD', '/items/7');
lifecycleCheck($match->status === 200 && $match->parameters['id'] === '7', 'HEAD matches GET without resolution');
lifecycleCheck($router->match('POST', '/items/7')->allowed === ['GET', 'HEAD'], 'Method mismatch keeps Allow metadata');
lifecycleCheck($router->match('GET', '/missing')->status === 404, 'Missing path is distinct from method mismatch');
$router->websocket('/socket', 'UnregisteredSocket');
lifecycleCheck($router->match('GET', '/socket')->websocket, 'WebSocket registration is metadata only');

/**
 * 返回固定匹配结果，验证路由匹配器可被替换。
 *
 * 构造参数：无，本类型未声明构造参数。
 *
 * 返回值：使用 new 构造时得到 AlternateMatcher 实例。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $kernel = new Loom\HttpKernel(new AlternateMatcher());
 */
class AlternateMatcher implements Loom\RouteMatcher
{
    /**
     * 忽略输入并返回固定路由，验证替换匹配器生效。
     *
     * @param string $method HTTP 方法字符串，例如 GET 或 POST。
     * @param string $path 待匹配的请求路径，例如 /items。
     * @return Loom\RouteMatch 参数 id 为 alternate 的成功匹配结果。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $match = $matcher->match('GET', '/');
     */
    public function match(string $method, string $path): Loom\RouteMatch
    {
        return new Loom\RouteMatch(200, static function (Loom\Request $request): Loom\Response {
            return new Loom\Response($request->parameter('id'));
        }, ['id' => 'alternate']);
    }
}
$kernel = new Loom\HttpKernel(new AlternateMatcher());
$kernelProvider = (new Loom\ServiceCollection())->build();
$alternate = $kernelProvider->withScope(static fn (Loom\ServiceScope $scope): Loom\Response => $kernel->handle(new Loom\Request(['method' => 'GET', 'path' => '/']), $scope));
lifecycleCheck($alternate->toArray()['body'] === 'alternate', 'Kernel accepts an independent matcher');

/**
 * 模拟原生请求的 URL、方法、路径与安全连接信息。
 *
 * 构造参数：
 * - $path（string）：模拟的请求路径。默认值为 '/items'。
 *
 * 返回值：使用 new 构造时得到 NativeRequestProbe 实例。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $native = new NativeRequestProbe('/items');
 */
class NativeRequestProbe
{
    public function __construct(private string $path = '/items')
    {
    }
    /**
     * 模拟含重复查询参数与自定义端口的完整请求 URL。
     *
     * 参数：无。
     *
     * @return string 固定主机与端口加上当前路径，查询为 q=a&q=b。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $url = $probe->fullUrl();
     */
    public function fullUrl(): string
    {
        return 'https://example.test:8443' . $this->path . '?q=a&q=b';
    }
    /**
     * 模拟原生请求方法。
     *
     * 参数：无。
     *
     * @return string 固定字符串 GET。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $method = $probe->method();
     */
    public function method(): string
    {
        return 'GET';
    }
    /**
     * 模拟原生请求路径。
     *
     * 参数：无。
     *
     * @return string 构造时给出的路径。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $path = $probe->path();
     */
    public function path(): string
    {
        return $this->path;
    }
    /**
     * 模拟使用安全连接的原生请求。
     *
     * 参数：无。
     *
     * @return bool 固定 true。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $secure = $probe->isSecure();
     */
    public function isSecure(): bool
    {
        return true;
    }
}
/**
 * 在内存中记录响应头、状态与正文，验证响应桥接。
 *
 * 构造参数：无，本类型未声明构造参数。
 *
 * 返回值：使用 new 构造时得到 NativeWriterProbe 实例。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $writer = new NativeWriterProbe();
 */
class NativeWriterProbe
{
    public array $headers = [];
    public int $status = 0;
    public string $body = '';
    /**
     * 记录响应头，供响应桥接断言读取。
     *
     * @param string $name 响应头名。
     * @param string $value 响应头的值。
     * @return void 无返回值，更新内存中的响应头。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $writer->header('Content-Type', 'text/plain');
     */
    public function header(string $name, string $value): void
    {
        $this->headers[$name] = $value;
    }
    /**
     * 记录响应状态码。
     *
     * @param int $status 待记录的 HTTP 状态码，例如 200 或 201。
     * @return void 无返回值，更新内存状态。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $writer->writeHeader(201);
     */
    public function writeHeader(int $status): void
    {
        $this->status = $status;
    }
    /**
     * 追加响应正文，模拟多次写出。
     *
     * @param string $body 要写出的响应正文字符串。
     * @return void 无返回值，累加正文。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $writer->write('完成');
     */
    public function write(string $body): void
    {
        $this->body .= $body;
    }
}

/**
 * 记录请求作用域里看到的 RequestContext，验证每个请求各有一份。
 */
final class LifecycleSeen
{
    public array $contexts = [];
}

$native = new NativeRequestProbe();
$request = Loom\RequestFactory::fromNative($native, ['HTTP_X_PROBE' => 'yes', 'CONTENT_TYPE' => 'application/json'], '{}', 16);
lifecycleCheck($request->query('q') === ['a', 'b'] && $request->header('x-probe') === 'yes', 'Native adapter preserves repeated query and headers');
lifecycleCheck($request->origin() === 'https://example.test:8443' && $request->body() === '{}', 'Native adapter preserves origin and body');
$rejected = false;
try {
    Loom\RequestFactory::fromNative($native, [], 'too large', 2);
} catch (Loom\HttpError $error) {
    $rejected = $error->status === 413;
}
lifecycleCheck($rejected, 'Body limits apply at transport boundary');

$root = sys_get_temp_dir() . '/loom-lifecycle-' . bin2hex(random_bytes(6));
mkdir($root . '/config', 0777, true);
$apps = [];
try {
    file_put_contents($root . '/config/http.php', '<?php return ["address" => "127.0.0.1:1"];');
    // 同一份登记构建出的两个应用各有自己的根容器和路由表。
    foreach (['first', 'second'] as $name) {
        $builder = Loom\Application::createBuilder($root);
        $builder->services->addSingleton(LifecycleSeen::class);
        $apps[$name] = $builder->build();
        $apps[$name]->mapGet('/shared', static fn (LifecycleSeen $seen): Loom\Response => new Loom\Response((string) spl_object_id($seen)));
    }
    $first = $apps['first'];
    $second = $apps['second'];
    lifecycleCheck($first->services->get(LifecycleSeen::class) !== $second->services->get(LifecycleSeen::class), 'Applications must isolate singletons');
    $first->mapGet('/first-only', static fn (): Loom\Response => new Loom\Response('first'));
    lifecycleCheck($second->handle(new Loom\Request(['method' => 'GET', 'path' => '/first-only']))->toArray()['status'] === 404, 'Applications must isolate route mutations');
    lifecycleCheck($first->handle(new Loom\Request(['method' => 'GET', 'path' => '/first-only']))->toArray()['body'] === 'first', 'Routes mapped after build are served');

    $network = $first->services->get(Loom\Network::class);
    $writer = new NativeWriterProbe();
    $transport = new Loom\HttpTransport($network, $native, $writer);
    $transport->send(new Loom\Response('hidden', 202), true);
    lifecycleCheck($writer->status === 202 && $writer->body === '', 'HEAD emits headers without body');

    // HttpServer 为每个原生请求开一个作用域：请求和传输只挂在这次作用域的 RequestContext 上。
    $first->mapGet('/items', static function (Loom\Request $request, Loom\RequestContext $context, LifecycleSeen $seen): Loom\Response {
        $seen->contexts[] = $context;
        return new Loom\Response($context->current()->path() . ':' . ($context->transport() instanceof Loom\HttpTransport ? 'transport' : 'none'));
    });
    $first->mapGet('/fails', static function (): Loom\Response {
        throw new Loom\HttpError(409, 'conflict');
    });
    $server = $first->services->get(Loom\HttpServer::class);
    $responses = [];
    foreach (['/items', '/fails', '/items'] as $path) {
        $writer = new NativeWriterProbe();
        $server->handle(new NativeRequestProbe($path), $writer);
        $responses[] = $writer;
    }
    lifecycleCheck($responses[0]->status === 200 && $responses[0]->body === '/items:transport', 'Server binds the request and transport in the request scope');
    lifecycleCheck($responses[1]->status === 409, 'A failed dispatch is written as its HTTP status');
    lifecycleCheck($responses[2]->status === 200 && $responses[2]->body === '/items:transport', 'A new request starts cleanly after a failed one');
    $seen = $first->services->get(LifecycleSeen::class);
    lifecycleCheck(count($seen->contexts) === 2 && $seen->contexts[0] !== $seen->contexts[1], 'Each request gets its own RequestContext');
    $first->services->withScope(static function (Loom\ServiceScope $scope): void {
        foreach (['request', 'transport'] as $kind) {
            $cleared = false;
            try {
                $kind === 'request' ? $scope->get(Loom\RequestContext::class)->current() : $scope->get(Loom\RequestContext::class)->transport();
            } catch (LogicException $error) {
                $cleared = true;
            }
            lifecycleCheck($cleared, 'A fresh scope has no ' . $kind);
        }
    });
} finally {
    foreach ($apps as $app) {
        $app->dispose();
    }
    foreach (glob($root . '/config/*') as $file) {
        unlink($file);
    }
    rmdir($root . '/config');
    rmdir($root);
}
echo "loom_lifecycle_test: OK\n";
