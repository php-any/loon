<?php

declare(strict_types=1);

$runtimeScope = (require dirname(__DIR__) . '/bootstrap/autoload.php')(dirname(__DIR__, 2));
$runtimeServices = $runtimeScope->container();
require __DIR__ . '/fixtures/modules/components.php';

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
 * 返回值：使用 new 构造时得到 AlternateMatcher 实例；注册到容器时由容器管理实例的创建与复用。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $instance = $services->get(AlternateMatcher::class); // 在对应测试环境中解析；无效夹具用于断言异常
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
        global $runtimeServices;
        return new Loom\RouteMatch(200, function ($request) use ($runtimeServices) {
            return new Loom\Response($request->parameter('id'));
        }, ['id' => 'alternate']);
    }
}
$kernel = new Loom\HttpKernel(new AlternateMatcher(), (new \Loom\ServiceScope($runtimeServices->get(\Loom\Config::class)))->container());
lifecycleCheck($kernel->handle(new Loom\Request(['method' => 'GET', 'path' => '/']))->toArray()['body'] === 'alternate', 'Kernel accepts an independent matcher');

// 工厂可以复用发现结果，但各应用拥有独立的容器和路由。
$modules = new \Loom\ModuleRegistry(new \Loom\ModuleDiscovery($runtimeServices->get(\Loom\Config::class)), $runtimeServices->get(\Loom\Config::class), $runtimeScope);
$modules->register(ModuleFixtures\Value::class);
$modules->register(ModuleFixtures\Endpoint::class);
$factory = new Loom\ApplicationFactory($modules);
$factory->routes(function (Loom\Router $routes, Loom\Application $app) use ($runtimeServices): void {
    $routes->get('/factory', function ($request) use ($app, $runtimeServices) {
        return new Loom\Response($app->container()->get('module.value')->text());
    });
});
$first = $factory->create();
$second = $factory->create();
lifecycleCheck($second->handle(new Loom\Request(['method' => 'GET', 'path' => '/factory']))->toArray()['body'] === 'injected', 'Factory callback receives the current application');
lifecycleCheck($first->container()->get('module.value') !== $second->container()->get('module.value'), 'Factory applications must isolate services');
$first->router()->get('/first-only', function ($request) use ($runtimeServices) {
    return new Loom\Response('first');
});
lifecycleCheck($second->router()->match('GET', '/first-only')->status === 404, 'Factory applications must isolate route mutations');
lifecycleCheck($second->handle(new Loom\Request(['method' => 'GET', 'path' => '/module/value']))->toArray()['body'] === 'injected12', 'Factory installs discovered routes and dependencies');

/**
 * 创建普通对象并记录初始化顺序，验证可替换对象工厂。
 *
 * 构造参数：无，本类型未声明构造参数。
 *
 * 返回值：使用 new 构造时得到 PlainObjectFactory 实例；注册到容器时由容器管理实例的创建与复用。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $instance = $services->get(PlainObjectFactory::class); // 在对应测试环境中解析；无效夹具用于断言异常
 */
class PlainObjectFactory implements Loom\ObjectFactory
{
    public int $initialized = 0;
    /**
     * 忽略请求类型并创建普通对象，供工厂替换测试使用。
     *
     * @param string $class 需要解析或创建的完整类名。
     * @param Loom\Container $services 提供依赖解析和实例注册的服务容器。
     * @return object 新的 stdClass 实例。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $object = $factory->create(stdClass::class, $services);
     */
    public function create(string $class, Loom\Container $services): object
    {
        return new stdClass();
    }
    /**
     * 增加初始化计数并把顺序编号写入对象。
     *
     * @param object $instance 需要初始化或注册的对象实例。
     * @param Loom\Container $services 提供依赖解析和实例注册的服务容器。
     * @return void 无返回值，修改实例的 sequence 属性。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $factory->initialize($object, $services);
     */
    public function initialize(object $instance, Loom\Container $services): void
    {
        $instance->sequence = ++$this->initialized;
    }
}
$objects = new PlainObjectFactory();
$services = new Loom\Container($objects);
$services->singleton('shared');
$services->transient('fresh');
lifecycleCheck($services->get('shared') === $services->get('shared'), 'Singleton identity belongs to the container');
lifecycleCheck($services->get('fresh') !== $services->get('fresh'), 'Transient resolutions create separate objects');
lifecycleCheck($objects->initialized === 3, 'Creation policy initializes each object once');
$services->alias('shared.alias', 'shared');
lifecycleCheck($services->get('shared.alias') === $services->get('shared') && $objects->initialized === 3, 'Aliases do not initialize the target again');
$services->alias('fresh.alias', 'fresh');
lifecycleCheck($services->get('fresh.alias') !== $services->get('fresh.alias'), 'Aliases preserve transient lifetime');
$services->transient('failure', function ($services) use ($runtimeServices) {
    throw new RuntimeException('failure');
});
try {
    $services->get('failure');
} catch (RuntimeException $error) {
}
lifecycleCheck($services->get('shared')->sequence === 1, 'Failed transient leaves native resolution state usable');

/**
 * 模拟原生请求的 URL、方法、路径与安全连接信息。
 *
 * 构造参数：无，本类型未声明构造参数。
 *
 * 返回值：使用 new 构造时得到 NativeRequestProbe 实例；注册到容器时由容器管理实例的创建与复用。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $instance = $services->get(NativeRequestProbe::class); // 在对应测试环境中解析；无效夹具用于断言异常
 */
class NativeRequestProbe
{
    /**
     * 模拟含重复查询参数与自定义端口的完整请求 URL。
     *
     * 参数：无。
     *
     * @return string 固定 URL https://example.test:8443/items?q=a&q=b。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $url = $probe->fullUrl();
     */
    public function fullUrl(): string
    {
        return 'https://example.test:8443/items?q=a&q=b';
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
     * @return string 固定路径 /items。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $path = $probe->path();
     */
    public function path(): string
    {
        return '/items';
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
 * 返回值：使用 new 构造时得到 NativeWriterProbe 实例；注册到容器时由容器管理实例的创建与复用。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $instance = $services->get(NativeWriterProbe::class); // 在对应测试环境中解析；无效夹具用于断言异常
 */
class NativeWriterProbe
{
    public array $headers = [];
    public int $status = 0;
    public string $body = '';
    /**
     * 记录响应头，供响应桥接断言读取。
     *
     * @param string $name 当前操作使用的名称；测试中作为响应头名或追踪标识。
     * @param string $value 夹具保存或使用的测试值；具体注入来源由参数属性声明。
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
$writer = new NativeWriterProbe();
$transport = new \Loom\HttpTransport($runtimeServices->get(\Loom\Network::class), $native, $writer);
$transport->send(new Loom\Response('hidden', 202), true);
lifecycleCheck($writer->status === 202 && $writer->body === '', 'HEAD emits headers without body');

$runtimeServices->get(\Loom\RequestScope::class)->open($transport);
$runtimeServices->get(\Loom\RequestContext::class)->bind($request);
$runtimeServices->get(\Loom\Database::class)->connect((string)getenv('LOOM_DATABASE'));
$runtimeServices->get(\Loom\Database::class)->execute('CREATE TABLE scope_probe (id INTEGER)');
$runtimeServices->get(\Loom\RequestScope::class)->close();
foreach (['request', 'transport'] as $kind) {
    $cleared = false;
    try {
        if ($kind === 'request') {
            $runtimeServices->get(\Loom\RequestContext::class)->current();
        } else {
            $runtimeServices->get(\Loom\RequestScope::class)->transport();
        }
    } catch (LogicException $error) {
        $cleared = true;
    }
    lifecycleCheck($cleared, 'Scope cleanup clears ' . $kind);
}
// 清理完成后可以开始新请求，即使前一次分发失败也不例外。
$runtimeServices->get(\Loom\RequestScope::class)->open(new \Loom\HttpTransport($runtimeServices->get(\Loom\Network::class), $native, new NativeWriterProbe()));
$runtimeServices->get(\Loom\RequestScope::class)->close();
echo "loom_lifecycle_test: OK\n";
