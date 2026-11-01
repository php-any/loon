<?php

declare(strict_types=1);

namespace Loom;

/**
 * 应用组合入口，持有独立容器、路由器和 HTTP 内核，提供路由注册与资源清理。
 *
 * 构造参数：
 * - $container（?Container）：服务容器；允许 null 时由当前对象创建或选择默认容器。默认值为 null。
 * - $router（?Router）：路由器或路由匹配器，负责解析请求处理目标。默认值为 null。
 *
 * 返回值：使用 new 构造时得到 Application 实例；注册到容器时由容器管理实例的创建与复用。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $app = new Application($services); $app->get('/ping', fn (Request $r) => Response::json(['ok' => true]));
 */
final class Application
{
    private Container $container;
    private Router $router;
    private HttpKernel $kernel;
    /**
     * 初始化 Application 实例及其依赖。应用组合入口，持有独立容器、路由器和 HTTP 内核，提供路由注册与资源清理。
     *
     * @param ?Container $container 服务容器；允许 null 时由当前对象创建或选择默认容器。默认值为 null。
     * @param ?Router $router 路由器或路由匹配器，负责解析请求处理目标。默认值为 null。
     * @return void 无直接返回值；通过 new 表达式取得初始化后的实例。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $app = new Application($services); $app->get('/ping', fn (Request $r) => Response::json(['ok' => true]));
     */
    public function __construct(?Container $container = null, ?Router $router = null)
    {
        $this->container = $container ?? new Container();
        $this->router = $router ?? new Router();
        $this->kernel = new HttpKernel($this->router, $this->container);
    }
    /**
     * 取得应用持有的服务容器。
     *
     * 参数：无。
     *
     * @return Container 当前应用容器。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $services = $app->services();
     */
    public function services(): Container
    {
        return $this->container;
    }
    /**
     * 取得应用路由器，供注册路由或直接匹配。
     *
     * 参数：无。
     *
     * @return Router 当前 Router。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $router = $app->router();
     */
    public function router(): Router
    {
        return $this->router;
    }
    /**
     * 取得负责请求处理的内核。
     *
     * 参数：无。
     *
     * @return HttpKernel 当前 HttpKernel。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $kernel = $app->kernel();
     */
    public function kernel(): HttpKernel
    {
        return $this->kernel;
    }
    /**
     * 把请求交给内核执行中间件及路由处理。
     *
     * @param Request $request 当前 HTTP 请求；中间件修改属性时应传递请求副本。
     * @return Response 最终 HTTP 响应。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $response = $app->handle($request);
     */
    public function handle(Request $request): Response
    {
        return $this->kernel->handle($request);
    }
    /**
     * 关闭已解析的请求作用域和数据库资源，并清理请求上下文。
     *
     * 参数：无。
     *
     * @return void 无返回值。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $app->close();
     */
    public function close(): void
    {
        $scope = $this->container->resolved(\Loom\ServiceScope::class);
        if ($scope !== null) {
            $scope->close();
        } else {
            try {
                $requestScope = $this->container->resolved(\Loom\RequestScope::class);
                if ($requestScope !== null) {
                    $requestScope->close();
                }
                $database = $this->container->resolved(\Loom\Database::class);
                if ($database !== null) {
                    $database->close();
                }
            } finally {
                $this->container->get(\Loom\RequestContext::class)->bind(null);
            }
        }
    }
    /**
     * 向内核追加全局中间件，包括未匹配到路由的请求也会经过它。
     *
     * @param mixed $middleware 中间件定义；列表按注册顺序执行，支持类名、Middleware 实例或回调。
     * @return self 当前 Application。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $app->middleware(\Loom\Http\Middleware\RequestLog::class);
     */
    public function middleware(mixed $middleware): self
    {
        $this->kernel->middleware($middleware);
        return $this;
    }
    /**
     * 设置后续注册使用的路径前缀，去掉末尾斜杠，不修改已注册路由。
     *
     * @param string $prefix 组合到路由前的路径前缀，例如 /api。
     * @return RouteRegistrar 带有新前缀的注册器；RouteRegistrar 返回副本。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $api = $routes->prefix('/api');
     */
    public function prefix(string $prefix): RouteRegistrar
    {
        return $this->router->prefix($prefix);
    }
    /**
     * 组合后续路由使用的中间件列表，不修改已注册路由。
     *
     * @param mixed ...$middleware 中间件定义；列表按注册顺序执行，支持类名、Middleware 实例或回调。支持传入多个值。
     * @return RouteRegistrar 携带中间件选项的注册器。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $jsonRoutes = $routes->withMiddleware(\Loom\Http\Middleware\ParseJsonInput::class);
     */
    public function withMiddleware(mixed ...$middleware): RouteRegistrar
    {
        return $this->router->withMiddleware(...$middleware);
    }
    /**
     * 在分组内注册路由并组合前缀及中间件，回调结束后恢复外层注册状态。
     *
     * @param string $prefix 组合到路由前的路径前缀，例如 /api。
     * @param callable $register 注册回调，接收 Router 并在其上声明路由。
     * @param array $middleware 中间件定义；列表按注册顺序执行，支持类名、Middleware 实例或回调。默认值为 []。
     * @return self 当前注册对象。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $routes->group('/api', static function (Router $r): void { $r->get('/ping', fn (Request $q) => Response::json(['ok' => true])); });
     */
    public function group(string $prefix, callable $register, array $middleware = []): self
    {
        $this->router->group($prefix, function ($routes) use ($register): void {
            $register($this);
        }, $middleware);
        return $this;
    }
    /**
     * 根据命名路由替换并编码路径参数，缺失、多余、空值或包含斜杠的参数会报错。
     *
     * @param string $name 命名路由标识；空字符串表示不命名。
     * @param array $parameters 命名路由占位符到值的映射，不允许缺失或多余参数。默认值为 []。
     * @return string 不包含域名的 URL 路径。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $url = $routes->url('items.show', ['id' => 42]);
     */
    public function url(string $name, array $parameters = []): string
    {
        return $this->router->url($name, $parameters);
    }
    /**
     * 注册指定 HTTP 方法的路由，保留处理器、中间件和可选名称。
     *
     * @param string $method HTTP 方法，可选 GET、HEAD、POST、PUT、PATCH、DELETE、OPTIONS。
     * @param string $path 以 / 开头的路由路径，可含 {id} 等完整路径段占位符；不包含查询串或片段。
     * @param mixed $action 控制器类名、可调用对象或 [控制器类名或实例, 方法名]；普通 HTTP 处理器须返回 Response。
     * @param string $name 命名路由标识；空字符串表示不命名。默认值为 ''。
     * @param array $middleware 中间件定义；列表按注册顺序执行，支持类名、Middleware 实例或回调。默认值为 []。
     * @return self 当前注册对象。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $routes->route('GET', '/items/{id}', $handler, 'items.show');
     */
    public function route(string $method, string $path, mixed $action, string $name = '', array $middleware = []): self
    {
        $this->router->route($method, $path, $action, $name, $middleware);
        return $this;
    }
    /**
     * 注册 GET 路由，控制器在分发时解析；同方法下重叠路径或重复名称会报错。
     *
     * @param string $path 以 / 开头的路由路径，可含 {id} 等完整路径段占位符；不包含查询串或片段。
     * @param mixed $action 控制器类名、可调用对象或 [控制器类名或实例, 方法名]；普通 HTTP 处理器须返回 Response。
     * @param string $name 命名路由标识；空字符串表示不命名。默认值为 ''。
     * @param array $middleware 中间件定义；列表按注册顺序执行，支持类名、Middleware 实例或回调。默认值为 []。
     * @return self 当前注册对象，供继续链式注册使用。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $routes->get('/items', fn (Request $request) => Response::json(['ok' => true]));
     */
    public function get(string $path, mixed $action, string $name = '', array $middleware = []): self
    {
        $this->router->get($path, $action, $name, $middleware);
        return $this;
    }
    /**
     * 注册 POST 路由，控制器在分发时解析；同方法下重叠路径或重复名称会报错。
     *
     * @param string $path 以 / 开头的路由路径，可含 {id} 等完整路径段占位符；不包含查询串或片段。
     * @param mixed $action 控制器类名、可调用对象或 [控制器类名或实例, 方法名]；普通 HTTP 处理器须返回 Response。
     * @param string $name 命名路由标识；空字符串表示不命名。默认值为 ''。
     * @param array $middleware 中间件定义；列表按注册顺序执行，支持类名、Middleware 实例或回调。默认值为 []。
     * @return self 当前注册对象，供继续链式注册使用。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $routes->post('/items', fn (Request $request) => Response::json(['ok' => true]));
     */
    public function post(string $path, mixed $action, string $name = '', array $middleware = []): self
    {
        $this->router->post($path, $action, $name, $middleware);
        return $this;
    }
    /**
     * 注册 PUT 路由，控制器在分发时解析；同方法下重叠路径或重复名称会报错。
     *
     * @param string $path 以 / 开头的路由路径，可含 {id} 等完整路径段占位符；不包含查询串或片段。
     * @param mixed $action 控制器类名、可调用对象或 [控制器类名或实例, 方法名]；普通 HTTP 处理器须返回 Response。
     * @param string $name 命名路由标识；空字符串表示不命名。默认值为 ''。
     * @param array $middleware 中间件定义；列表按注册顺序执行，支持类名、Middleware 实例或回调。默认值为 []。
     * @return self 当前注册对象，供继续链式注册使用。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $routes->put('/items', fn (Request $request) => Response::json(['ok' => true]));
     */
    public function put(string $path, mixed $action, string $name = '', array $middleware = []): self
    {
        $this->router->put($path, $action, $name, $middleware);
        return $this;
    }
    /**
     * 注册 PATCH 路由，控制器在分发时解析；同方法下重叠路径或重复名称会报错。
     *
     * @param string $path 以 / 开头的路由路径，可含 {id} 等完整路径段占位符；不包含查询串或片段。
     * @param mixed $action 控制器类名、可调用对象或 [控制器类名或实例, 方法名]；普通 HTTP 处理器须返回 Response。
     * @param string $name 命名路由标识；空字符串表示不命名。默认值为 ''。
     * @param array $middleware 中间件定义；列表按注册顺序执行，支持类名、Middleware 实例或回调。默认值为 []。
     * @return self 当前注册对象，供继续链式注册使用。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $routes->patch('/items', fn (Request $request) => Response::json(['ok' => true]));
     */
    public function patch(string $path, mixed $action, string $name = '', array $middleware = []): self
    {
        $this->router->patch($path, $action, $name, $middleware);
        return $this;
    }
    /**
     * 注册 DELETE 路由，控制器在分发时解析；同方法下重叠路径或重复名称会报错。
     *
     * @param string $path 以 / 开头的路由路径，可含 {id} 等完整路径段占位符；不包含查询串或片段。
     * @param mixed $action 控制器类名、可调用对象或 [控制器类名或实例, 方法名]；普通 HTTP 处理器须返回 Response。
     * @param string $name 命名路由标识；空字符串表示不命名。默认值为 ''。
     * @param array $middleware 中间件定义；列表按注册顺序执行，支持类名、Middleware 实例或回调。默认值为 []。
     * @return self 当前注册对象，供继续链式注册使用。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $routes->delete('/items', fn (Request $request) => Response::json(['ok' => true]));
     */
    public function delete(string $path, mixed $action, string $name = '', array $middleware = []): self
    {
        $this->router->delete($path, $action, $name, $middleware);
        return $this;
    }
    /**
     * 注册 HEAD 路由，控制器在分发时解析；同方法下重叠路径或重复名称会报错。
     *
     * @param string $path 以 / 开头的路由路径，可含 {id} 等完整路径段占位符；不包含查询串或片段。
     * @param mixed $action 控制器类名、可调用对象或 [控制器类名或实例, 方法名]；普通 HTTP 处理器须返回 Response。
     * @param string $name 命名路由标识；空字符串表示不命名。默认值为 ''。
     * @param array $middleware 中间件定义；列表按注册顺序执行，支持类名、Middleware 实例或回调。默认值为 []。
     * @return self 当前注册对象，供继续链式注册使用。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $routes->head('/items', fn (Request $request) => Response::json(['ok' => true]));
     */
    public function head(string $path, mixed $action, string $name = '', array $middleware = []): self
    {
        $this->router->head($path, $action, $name, $middleware);
        return $this;
    }
    /**
     * 注册 OPTIONS 路由，控制器在分发时解析；同方法下重叠路径或重复名称会报错。
     *
     * @param string $path 以 / 开头的路由路径，可含 {id} 等完整路径段占位符；不包含查询串或片段。
     * @param mixed $action 控制器类名、可调用对象或 [控制器类名或实例, 方法名]；普通 HTTP 处理器须返回 Response。
     * @param string $name 命名路由标识；空字符串表示不命名。默认值为 ''。
     * @param array $middleware 中间件定义；列表按注册顺序执行，支持类名、Middleware 实例或回调。默认值为 []。
     * @return self 当前注册对象，供继续链式注册使用。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $routes->options('/items', fn (Request $request) => Response::json(['ok' => true]));
     */
    public function options(string $path, mixed $action, string $name = '', array $middleware = []): self
    {
        $this->router->options($path, $action, $name, $middleware);
        return $this;
    }
    /**
     * 注册需要升级连接的 GET 路由；处理器接收请求和原生 WebSocket 连接。
     *
     * @param string $path 以 / 开头的路由路径，可含 {id} 等完整路径段占位符；不包含查询串或片段。
     * @param mixed $action 控制器类名、可调用对象或 [控制器类名或实例, 方法名]；普通 HTTP 处理器须返回 Response。
     * @param string $name 命名路由标识；空字符串表示不命名。默认值为 ''。
     * @param array $middleware 中间件定义；列表按注册顺序执行，支持类名、Middleware 实例或回调。默认值为 []。
     * @return self 当前注册对象。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $routes->websocket('/socket', $socketHandler);
     */
    public function websocket(string $path, mixed $action, string $name = '', array $middleware = []): self
    {
        $this->router->websocket($path, $action, $name, $middleware);
        return $this;
    }
}
