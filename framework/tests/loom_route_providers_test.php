<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

/**
 * 断言测试条件成立，失败时立即抛出异常以终止当前测试。
 *
 * @param bool $ok 待断言的条件；为假时抛出测试失败异常。
 * @param string $message 断言失败时抛出的说明。
 * @return void 无返回值；条件失败时抛出 RuntimeException。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example providerCheck(true, '验证说明');
 */
function providerCheck(bool $ok, string $message): void
{
    if (!$ok) {
        throw new RuntimeException($message);
    }
}

/**
 * 注册固定 HTTP 和 WebSocket 路由并计数，验证显式路由提供器及其作用域。
 *
 * 构造参数：无，本类型未声明构造参数。
 *
 * 返回值：使用 new 构造时得到 FixtureHttpRoutes 实例；注册到容器时由容器管理实例的创建与复用。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $instance = $services->get(FixtureHttpRoutes::class); // 在对应测试环境中解析；无效夹具用于断言异常
 */
final class FixtureHttpRoutes implements Loom\Http\RouteProvider
{
    public int $calls = 0;
    /**
     * 注册测试 HTTP 和 WebSocket 路由并增加注册次数。
     *
     * @param Loom\Router $routes 接收测试 HTTP 和 WebSocket 路由的路由器。
     * @return void 无返回值，修改路由表和 calls。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $provider->register($router);
     */
    public function register(Loom\Router $routes): void
    {
        $this->calls++;
        $routes->get('/explicit', function ($request) { return new Loom\Response('explicit'); });
        $routes->websocket('/explicit-socket', 'FixtureSocket');
    }
}

/**
 * 提供固定 RPC 路由映射，验证应用路由优先于生成清单。
 *
 * 构造参数：无，本类型未声明构造参数。
 *
 * 返回值：使用 new 构造时得到 FixtureGrpcRoutes 实例；注册到容器时由容器管理实例的创建与复用。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $instance = $services->get(FixtureGrpcRoutes::class); // 在对应测试环境中解析；无效夹具用于断言异常
 */
final class FixtureGrpcRoutes implements Loom\Grpc\RouteProvider
{
    /**
     * 提供回显服务的显式 RPC 映射。
     *
     * 参数：无。
     *
     * @return array 包含 /fixture/echo 到 FixtureRpc::echoValue 的映射。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $routes = $provider->routes();
     */
    public function routes(): array
    {
        return ['/fixture/echo' => [FixtureRpc::class, 'echoValue']];
    }
}

/**
 * 原样返回 RPC 输入，供协议分发测试断言数据一致性。
 *
 * 构造参数：无，本类型未声明构造参数。
 *
 * 返回值：使用 new 构造时得到 FixtureRpc 实例；注册到容器时由容器管理实例的创建与复用。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $instance = $services->get(FixtureRpc::class); // 在对应测试环境中解析；无效夹具用于断言异常
 */
final class FixtureRpc
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

$root = sys_get_temp_dir() . '/loom-route-providers-' . bin2hex(random_bytes(6));
mkdir($root . '/routes', 0700, true);
mkdir($root . '/config', 0700, true);
mkdir($root . '/generated/grpc', 0700, true);
foreach (['web', 'websocket', 'grpc'] as $name) {
    file_put_contents($root . '/routes/' . $name . '.php', '<?php throw new RuntimeException("Implicit route file loaded");');
}
// 生成的 RPC 方法仍然可用；应用显式声明的映射具有更高优先级。
file_put_contents($root . '/generated/grpc/routes.json', json_encode(['files' => [['routes' => [
    '/fixture/generated' => [FixtureRpc::class, 'echoValue'],
    '/fixture/echo' => ['MissingService', 'missing'],
]]]]));
$scope = new Loom\ServiceScope(new Loom\Config($root), static function (Loom\Container $services): void {
    $services->scoped(Loom\Http\RouteProvider::class, FixtureHttpRoutes::class);
    $services->scoped(Loom\Grpc\RouteProvider::class, FixtureGrpcRoutes::class);
});
$empty = new Loom\ServiceScope(new Loom\Config($root));
$applications = [];
try {
    $httpEntry = dirname(__DIR__) . '/bootstrap/http.php';
    $grpcEntry = dirname(__DIR__) . '/bootstrap/grpc.php';
    $app = $scope->load($httpEntry);
    providerCheck($app->router()->match('GET', '/explicit')->status === 200, 'Explicit HTTP routes');
    providerCheck($app->router()->match('GET', '/explicit-socket')->websocket, 'Explicit WebSocket routes');
    $handler = $scope->load($grpcEntry);
    foreach (['/fixture/echo', '/fixture/generated'] as $method) {
        providerCheck($handler(['method' => $method, 'request' => ['value' => 7]]) === ['data' => ['value' => 7]], 'Explicit and generated RPC dispatch');
    }
    $factory = new Loom\ApplicationFactory($scope->services()->get(Loom\ModuleRegistry::class)->configured());
    $applications[] = $factory->create();
    $applications[] = $factory->create();
    foreach ($applications as $application) {
        providerCheck($application->router()->match('GET', '/explicit')->status === 200, 'Factory installs interface routes');
        providerCheck($application->services()->get(Loom\Http\RouteProvider::class)->calls === 1, 'Register once per application');
    }
    providerCheck($applications[0]->services()->get(Loom\Http\RouteProvider::class) !== $applications[1]->services()->get(Loom\Http\RouteProvider::class), 'Providers are scoped');
    providerCheck($empty->load($httpEntry)->router()->match('GET', '/explicit')->status === 404, 'HTTP provider is optional');
    $handler = $empty->load($grpcEntry);
    providerCheck($handler(['method' => '/fixture/unknown', 'request' => []])['error']['code'] === 'unimplemented', 'Missing RPC provider');
    providerCheck($handler(['method' => '/fixture/generated', 'request' => []]) === ['data' => []], 'Generated RPC works without provider');
} finally {
    foreach ($applications as $application) {
        $application->close();
    }
    $scope->close();
    $empty->close();
    foreach (['web', 'websocket', 'grpc'] as $name) {
        unlink($root . '/routes/' . $name . '.php');
    }
    unlink($root . '/generated/grpc/routes.json');
    rmdir($root . '/generated/grpc');
    rmdir($root . '/generated');
    rmdir($root . '/routes');
    rmdir($root . '/config');
    rmdir($root);
}
// 验证迁移后的应用映射，不打开数据库连接或启动服务器。
$applicationScope = (require dirname(__DIR__) . '/bootstrap/autoload.php')(dirname(__DIR__, 2), static function (Loom\Container $services): void {
    $services->scoped(Loom\Http\RouteProvider::class, App\Http\ApplicationRoutes::class);
    $services->scoped(Loom\Grpc\RouteProvider::class, App\Grpc\ApplicationRoutes::class);
});
try {
    $app = $applicationScope->load('framework/bootstrap/http.php');
    providerCheck($app->router()->match('GET', '/demo')->status === 200, 'Application annotation routes remain available');
    providerCheck($app->router()->match('GET', '/api/admin/notifications/socket')->websocket, 'Application WebSocket annotation remains available');
    $handler = $applicationScope->load('framework/bootstrap/grpc.php');
    $result = $handler(['method' => '/loom.examples.ReleasePolicy/Evaluate', 'request' => '{"total":2,"completed":2}']);
    providerCheck(json_decode($result['data'], true)['ready'] === true, 'Migrated release policy mapping');
    $result = $handler(['method' => '/loom.examples.ReleasePolicy/Evaluate', 'request' => '{}']);
    providerCheck($result['error']['code'] === 'invalid_argument', 'RPC status mapping is preserved');
} finally {
    $applicationScope->close();
}
echo "loom_route_providers_test: OK\n";
