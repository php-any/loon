<?php

declare(strict_types=1);

namespace Loom;

/**
 * 保存不可变的路由前缀和中间件选项，将实际注册委托给 Router。
 *
 * 构造参数：
 * - $router（Router）：路由器或路由匹配器，负责解析请求处理目标。
 * - $prefix（string）：组合到路由前的路径前缀，例如 /api。默认值为 ''。
 * - $middleware（array）：中间件定义；列表按注册顺序执行，支持类名、Middleware 实例或回调。默认值为 []。
 *
 * 返回值：使用 new 构造时得到 RouteRegistrar 实例；注册到容器时由容器管理实例的创建与复用。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $api = $router->prefix('/api'); $api->get('/ping', fn (Request $r) => Response::json(['ok' => true]));
 */
final class RouteRegistrar
{
    private Router $router;
    private string $prefix;
    private array $middleware;
    /**
     * 初始化 RouteRegistrar 实例及其依赖。保存不可变的路由前缀和中间件选项，将实际注册委托给 Router。
     *
     * @param Router $router 路由器或路由匹配器，负责解析请求处理目标。
     * @param string $prefix 组合到路由前的路径前缀，例如 /api。默认值为 ''。
     * @param array $middleware 中间件定义；列表按注册顺序执行，支持类名、Middleware 实例或回调。默认值为 []。
     * @return void 无直接返回值；通过 new 表达式取得初始化后的实例。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $api = $router->prefix('/api'); $api->get('/ping', fn (Request $r) => Response::json(['ok' => true]));
     */
    public function __construct(Router $router, string $prefix = '', array $middleware = [])
    {
        $this->router = $router;
        $this->prefix = $prefix;
        $this->middleware = $middleware;
    }
    /**
     * 设置后续注册使用的路径前缀，去掉末尾斜杠，不修改已注册路由。
     *
     * @param string $prefix 组合到路由前的路径前缀，例如 /api。
     * @return self 带有新前缀的注册器；RouteRegistrar 返回副本。
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
     * 组合后续路由使用的中间件列表，不修改已注册路由。
     *
     * @param mixed ...$middleware 中间件定义；列表按注册顺序执行，支持类名、Middleware 实例或回调。支持传入多个值。
     * @return self 携带中间件选项的注册器。
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
     * 在分组内注册路由并组合前缀及中间件，回调结束后恢复外层注册状态。
     *
     * @param callable $register 注册回调，接收 Router 并在其上声明路由。
     * @return self 当前注册对象。
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
     * 注册指定 HTTP 方法的路由，保留处理器、中间件和可选名称。
     *
     * @param string $method HTTP 方法，可选 GET、HEAD、POST、PUT、PATCH、DELETE、OPTIONS。
     * @param string $path 以 / 开头的路由路径，可含 {id} 等完整路径段占位符；不包含查询串或片段。
     * @param mixed $action 控制器类名、可调用对象或 [控制器类名或实例, 方法名]；普通 HTTP 处理器须返回 Response。
     * @param string $name 命名路由标识；空字符串表示不命名。默认值为 ''。
     * @return self 当前注册对象。
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
     * 注册 GET 路由，控制器在分发时解析；同方法下重叠路径或重复名称会报错。
     *
     * @param string $path 以 / 开头的路由路径，可含 {id} 等完整路径段占位符；不包含查询串或片段。
     * @param mixed $action 控制器类名、可调用对象或 [控制器类名或实例, 方法名]；普通 HTTP 处理器须返回 Response。
     * @param string $name 命名路由标识；空字符串表示不命名。默认值为 ''。
     * @return self 当前注册对象，供继续链式注册使用。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $routes->get('/items', fn (Request $request) => Response::json(['ok' => true]));
     */
    public function get(string $path, mixed $action, string $name = ''): self
    {
        return $this->route('GET', $path, $action, $name);
    }
    /**
     * 注册 POST 路由，控制器在分发时解析；同方法下重叠路径或重复名称会报错。
     *
     * @param string $path 以 / 开头的路由路径，可含 {id} 等完整路径段占位符；不包含查询串或片段。
     * @param mixed $action 控制器类名、可调用对象或 [控制器类名或实例, 方法名]；普通 HTTP 处理器须返回 Response。
     * @param string $name 命名路由标识；空字符串表示不命名。默认值为 ''。
     * @return self 当前注册对象，供继续链式注册使用。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $routes->post('/items', fn (Request $request) => Response::json(['ok' => true]));
     */
    public function post(string $path, mixed $action, string $name = ''): self
    {
        return $this->route('POST', $path, $action, $name);
    }
    /**
     * 注册 PUT 路由，控制器在分发时解析；同方法下重叠路径或重复名称会报错。
     *
     * @param string $path 以 / 开头的路由路径，可含 {id} 等完整路径段占位符；不包含查询串或片段。
     * @param mixed $action 控制器类名、可调用对象或 [控制器类名或实例, 方法名]；普通 HTTP 处理器须返回 Response。
     * @param string $name 命名路由标识；空字符串表示不命名。默认值为 ''。
     * @return self 当前注册对象，供继续链式注册使用。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $routes->put('/items', fn (Request $request) => Response::json(['ok' => true]));
     */
    public function put(string $path, mixed $action, string $name = ''): self
    {
        return $this->route('PUT', $path, $action, $name);
    }
    /**
     * 注册 PATCH 路由，控制器在分发时解析；同方法下重叠路径或重复名称会报错。
     *
     * @param string $path 以 / 开头的路由路径，可含 {id} 等完整路径段占位符；不包含查询串或片段。
     * @param mixed $action 控制器类名、可调用对象或 [控制器类名或实例, 方法名]；普通 HTTP 处理器须返回 Response。
     * @param string $name 命名路由标识；空字符串表示不命名。默认值为 ''。
     * @return self 当前注册对象，供继续链式注册使用。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $routes->patch('/items', fn (Request $request) => Response::json(['ok' => true]));
     */
    public function patch(string $path, mixed $action, string $name = ''): self
    {
        return $this->route('PATCH', $path, $action, $name);
    }
    /**
     * 注册 DELETE 路由，控制器在分发时解析；同方法下重叠路径或重复名称会报错。
     *
     * @param string $path 以 / 开头的路由路径，可含 {id} 等完整路径段占位符；不包含查询串或片段。
     * @param mixed $action 控制器类名、可调用对象或 [控制器类名或实例, 方法名]；普通 HTTP 处理器须返回 Response。
     * @param string $name 命名路由标识；空字符串表示不命名。默认值为 ''。
     * @return self 当前注册对象，供继续链式注册使用。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $routes->delete('/items', fn (Request $request) => Response::json(['ok' => true]));
     */
    public function delete(string $path, mixed $action, string $name = ''): self
    {
        return $this->route('DELETE', $path, $action, $name);
    }
    /**
     * 注册 HEAD 路由，控制器在分发时解析；同方法下重叠路径或重复名称会报错。
     *
     * @param string $path 以 / 开头的路由路径，可含 {id} 等完整路径段占位符；不包含查询串或片段。
     * @param mixed $action 控制器类名、可调用对象或 [控制器类名或实例, 方法名]；普通 HTTP 处理器须返回 Response。
     * @param string $name 命名路由标识；空字符串表示不命名。默认值为 ''。
     * @return self 当前注册对象，供继续链式注册使用。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $routes->head('/items', fn (Request $request) => Response::json(['ok' => true]));
     */
    public function head(string $path, mixed $action, string $name = ''): self
    {
        return $this->route('HEAD', $path, $action, $name);
    }
    /**
     * 注册 OPTIONS 路由，控制器在分发时解析；同方法下重叠路径或重复名称会报错。
     *
     * @param string $path 以 / 开头的路由路径，可含 {id} 等完整路径段占位符；不包含查询串或片段。
     * @param mixed $action 控制器类名、可调用对象或 [控制器类名或实例, 方法名]；普通 HTTP 处理器须返回 Response。
     * @param string $name 命名路由标识；空字符串表示不命名。默认值为 ''。
     * @return self 当前注册对象，供继续链式注册使用。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $routes->options('/items', fn (Request $request) => Response::json(['ok' => true]));
     */
    public function options(string $path, mixed $action, string $name = ''): self
    {
        return $this->route('OPTIONS', $path, $action, $name);
    }
    /**
     * 注册需要升级连接的 GET 路由；处理器接收请求和原生 WebSocket 连接。
     *
     * @param string $path 以 / 开头的路由路径，可含 {id} 等完整路径段占位符；不包含查询串或片段。
     * @param mixed $action 控制器类名、可调用对象或 [控制器类名或实例, 方法名]；普通 HTTP 处理器须返回 Response。
     * @param string $name 命名路由标识；空字符串表示不命名。默认值为 ''。
     * @return self 当前注册对象。
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
