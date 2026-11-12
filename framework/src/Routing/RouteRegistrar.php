<?php

declare(strict_types=1);

namespace Loom;

/**
 * 记住一组路径前缀和中间件，把真正的路由登记委托给 Router。前缀和中间件通过副本更换，不改已登记的路由。
 *
 * 构造参数：
 * - $router（Router）：实际执行注册的路由器。
 * - $prefix（string）：加在后续路径前的前缀。默认空字符串。
 * - $middleware（array）：加在后续路由上的中间件定义。默认空数组。
 *
 * 返回值：使用 new 构造时得到 RouteRegistrar 实例。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $routes = new RouteRegistrar($router, '/api', []);
 */
final class RouteRegistrar
{
    private Router $router;
    private string $prefix;
    private array $middleware;
    /**
     * 保存路由器、路径前缀和中间件列表。构造时不登记路由，也不检查中间件定义。
     *
     * @param Router $router 实际执行注册的路由器。
     * @param string $prefix 加在后续路径前的前缀。默认空字符串。
     * @param array $middleware 加在后续路由上的中间件定义。默认空数组。
     * @return void 无直接返回值。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $routes = new RouteRegistrar($router, '/api', []);
     */
    public function __construct(Router $router, string $prefix = '', array $middleware = [])
    {
        $this->router = $router;
        $this->prefix = $prefix;
        $this->middleware = $middleware;
    }
    /**
     * 克隆注册器，并把去掉末尾斜杠的前缀接到已有前缀后面。不改当前实例，也不改已登记的路由。
     *
     * @param string $prefix 要追加的路径前缀，例如 /api。末尾斜杠会被去掉。
     * @return self 带有更长前缀的副本。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $api = $routes->prefix('/api');
     */
    public function prefix(string $prefix): self
    {
        $copy = clone $this;
        $copy->prefix .= rtrim($prefix, '/');
        return $copy;
    }
    /**
     * 克隆注册器，把新的中间件接到已有列表后面。不检查这些定义，也不改当前实例或已登记路由。
     *
     * @param mixed ...$middleware 要追加的中间件，可以是类名、Middleware 实例或可调用对象。
     * @return self 带有更长中间件列表的副本。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $jsonRoutes = $routes->withMiddleware(\Loom\Http\Middleware\ParseJsonInput::class);
     */
    public function withMiddleware(mixed ...$middleware): self
    {
        $copy = clone $this;
        $copy->middleware = array_merge($this->middleware, $middleware);
        return $copy;
    }
    /**
     * 把当前前缀和中间件交给路由器的 group，在回调里登记路由。回调结束后由路由器恢复外层状态。返回的是当前实例。
     *
     * @param callable $register 注册回调，参数是 Router。
     * @return self 当前注册器。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $api->group(static function (Router $r): void { $r->get('/ping', fn (Request $request) => Response::json(['ok' => true])); });
     */
    public function group(callable $register): self
    {
        $this->router->group($this->prefix, $register, $this->middleware);
        return $this;
    }
    /**
     * 在当前前缀和中间件下登记一条指定方法的路由。路径、方法、名称或处理器不合法时，由路由器抛出 LogicException。
     *
     * @param string $method HTTP 方法。路由器只接受 GET、HEAD、POST、PUT、PATCH、DELETE、OPTIONS。
     * @param string $path 相对当前前缀的路径，需以 / 开头，可含 {id} 这样的整段占位符。
     * @param mixed $action [控制器, 方法名]（方法名不能是 __invoke），或非字符串闭包/可调用对象。
     * @param string $name 路由名。空字符串表示不命名。默认空字符串。
     * @return self 当前注册器。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $routes->route('GET', '/items/{id}', $handler, 'items.show');
     */
    public function route(string $method, string $path, mixed $action, string $name = ''): self
    {
        $this->group(function (Router $routes) use ($method, $path, $action, $name): void {
            $routes->route($method, $path, $action, $name);
        });
        return $this;
    }
    /**
     * 登记一条 GET 路由，等价于 route('GET', ...)。同方法路径重叠或路由名重复时由路由器抛出 LogicException。
     *
     * @param string $path 以 / 开头的路径，可含整段占位符，不含查询串或片段。
     * @param mixed $action [控制器, 方法名]（方法名不能是 __invoke），或非字符串闭包/可调用对象。
     * @param string $name 路由名。空字符串表示不命名。默认空字符串。
     * @return self 当前注册器。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $routes->get('/items', fn (Request $request) => Response::json(['ok' => true]));
     */
    public function get(string $path, mixed $action, string $name = ''): self
    {
        return $this->route('GET', $path, $action, $name);
    }
    /**
     * 登记一条 POST 路由，等价于 route('POST', ...)。同方法路径重叠或路由名重复时由路由器抛出 LogicException。
     *
     * @param string $path 以 / 开头的路径，可含整段占位符，不含查询串或片段。
     * @param mixed $action [控制器, 方法名]（方法名不能是 __invoke），或非字符串闭包/可调用对象。
     * @param string $name 路由名。空字符串表示不命名。默认空字符串。
     * @return self 当前注册器。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $routes->post('/items', fn (Request $request) => Response::json(['ok' => true]));
     */
    public function post(string $path, mixed $action, string $name = ''): self
    {
        return $this->route('POST', $path, $action, $name);
    }
    /**
     * 登记一条 PUT 路由，等价于 route('PUT', ...)。同方法路径重叠或路由名重复时由路由器抛出 LogicException。
     *
     * @param string $path 以 / 开头的路径，可含整段占位符，不含查询串或片段。
     * @param mixed $action [控制器, 方法名]（方法名不能是 __invoke），或非字符串闭包/可调用对象。
     * @param string $name 路由名。空字符串表示不命名。默认空字符串。
     * @return self 当前注册器。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $routes->put('/items', fn (Request $request) => Response::json(['ok' => true]));
     */
    public function put(string $path, mixed $action, string $name = ''): self
    {
        return $this->route('PUT', $path, $action, $name);
    }
    /**
     * 登记一条 PATCH 路由，等价于 route('PATCH', ...)。同方法路径重叠或路由名重复时由路由器抛出 LogicException。
     *
     * @param string $path 以 / 开头的路径，可含整段占位符，不含查询串或片段。
     * @param mixed $action [控制器, 方法名]（方法名不能是 __invoke），或非字符串闭包/可调用对象。
     * @param string $name 路由名。空字符串表示不命名。默认空字符串。
     * @return self 当前注册器。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $routes->patch('/items', fn (Request $request) => Response::json(['ok' => true]));
     */
    public function patch(string $path, mixed $action, string $name = ''): self
    {
        return $this->route('PATCH', $path, $action, $name);
    }
    /**
     * 登记一条 DELETE 路由，等价于 route('DELETE', ...)。同方法路径重叠或路由名重复时由路由器抛出 LogicException。
     *
     * @param string $path 以 / 开头的路径，可含整段占位符，不含查询串或片段。
     * @param mixed $action [控制器, 方法名]（方法名不能是 __invoke），或非字符串闭包/可调用对象。
     * @param string $name 路由名。空字符串表示不命名。默认空字符串。
     * @return self 当前注册器。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $routes->delete('/items', fn (Request $request) => Response::json(['ok' => true]));
     */
    public function delete(string $path, mixed $action, string $name = ''): self
    {
        return $this->route('DELETE', $path, $action, $name);
    }
    /**
     * 登记一条 HEAD 路由，等价于 route('HEAD', ...)。同方法路径重叠或路由名重复时由路由器抛出 LogicException。
     *
     * @param string $path 以 / 开头的路径，可含整段占位符，不含查询串或片段。
     * @param mixed $action [控制器, 方法名]（方法名不能是 __invoke），或非字符串闭包/可调用对象。
     * @param string $name 路由名。空字符串表示不命名。默认空字符串。
     * @return self 当前注册器。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $routes->head('/items', fn (Request $request) => Response::json(['ok' => true]));
     */
    public function head(string $path, mixed $action, string $name = ''): self
    {
        return $this->route('HEAD', $path, $action, $name);
    }
    /**
     * 登记一条 OPTIONS 路由，等价于 route('OPTIONS', ...)。同方法路径重叠或路由名重复时由路由器抛出 LogicException。
     *
     * @param string $path 以 / 开头的路径，可含整段占位符，不含查询串或片段。
     * @param mixed $action [控制器, 方法名]（方法名不能是 __invoke），或非字符串闭包/可调用对象。
     * @param string $name 路由名。空字符串表示不命名。默认空字符串。
     * @return self 当前注册器。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $routes->options('/items', fn (Request $request) => Response::json(['ok' => true]));
     */
    public function options(string $path, mixed $action, string $name = ''): self
    {
        return $this->route('OPTIONS', $path, $action, $name);
    }
    /**
     * 在当前前缀和中间件下登记一条 WebSocket 路由。处理器稍后接收请求和原生连接，这里不升级连接。
     *
     * @param string $path 以 / 开头的路径，可含整段占位符。
     * @param mixed $action 升级后调用的处理器。[控制器, 方法名]（方法名不能是 __invoke），或非字符串闭包/可调用对象。
     * @param string $name 路由名。空字符串表示不命名。默认空字符串。
     * @return self 当前注册器。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $routes->websocket('/socket', $socketHandler);
     */
    public function websocket(string $path, mixed $action, string $name = ''): self
    {
        $this->group(function (Router $routes) use ($path, $action, $name): void {
            $routes->websocket($path, $action, $name);
        });
        return $this;
    }
}
