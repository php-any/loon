<?php

declare(strict_types=1);

namespace Loom;

/**
 * 把容器、路由器和 HTTP 内核放在同一个应用对象里。路由登记方法都转给这只路由器，并返回应用自身。
 *
 * 构造参数：
 * - $container（?Container）：应用容器。null 时新建一个空容器。默认 null。
 * - $router（?Router）：应用路由器。null 时新建一个空路由器。默认 null。
 *
 * 返回值：使用 new 构造时得到已经接上 HttpKernel 的 Application。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $app = new Application($services);
 */
final class Application
{
    private Container $container;
    private Router $router;
    private HttpKernel $kernel;
    /**
     * 保存容器和路由器；其中为 null 的那个会换成新的空对象。然后用这两者新建 HttpKernel。
     *
     * @param ?Container $container 应用使用的容器。null 时新建 Container。默认 null。
     * @param ?Router $router 应用使用的路由器。null 时新建 Router。默认 null。
     * @return void 无直接返回值。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $app = new Application($services, $router);
     */
    public function __construct(?Container $container = null, ?Router $router = null)
    {
        $this->container = $container ?? new Container();
        $this->router = $router ?? new Router();
        $this->kernel = new HttpKernel($this->router, $this->container);
    }
    /**
     * 返回构造时保存的那只容器。
     *
     * 参数：无。
     *
     * @return Container 构造参数传入的容器；当时为 null 时则是构造函数新建的容器。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $container = $app->container();
     */
    public function container(): Container
    {
        return $this->container;
    }
    /**
     * 返回构造时保存的那只路由器。
     *
     * 参数：无。
     *
     * @return Router 构造参数传入的路由器；当时为 null 时则是构造函数新建的路由器。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $router = $app->router();
     */
    public function router(): Router
    {
        return $this->router;
    }
    /**
     * 返回构造时用容器和路由器创建的 HttpKernel。
     *
     * 参数：无。
     *
     * @return HttpKernel 处理本应用请求的内核。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $kernel = $app->kernel();
     */
    public function kernel(): HttpKernel
    {
        return $this->kernel;
    }
    /**
     * 把请求交给构造时创建的内核执行。本方法不关闭作用域，也不改写请求。
     *
     * @param Request $request 交给内核的请求。
     * @return Response 内核返回的响应。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $response = $app->handle($request);
     */
    public function handle(Request $request): Response
    {
        return $this->kernel->handle($request);
    }
    /**
     * 容器里已经解析出 ServiceScope 时，只关闭该作用域并返回。
     * 尚未解析出 ServiceScope 时，关闭已解析的 RequestScope 和 Database；finally 里再 get 出 RequestContext 并绑回 null。
     * 这条 finally 会在 RequestContext 尚未解析时把它创建出来。关闭过程中的异常会继续抛出。
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
     * 把一个全局中间件交给内核。之后由该内核处理的请求都会经过它，包括没有匹配到路由的请求。
     * 返回当前应用。
     *
     * @param mixed $middleware 交给内核的中间件。类名、实例或可调用对象的含义由内核解释。
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
     * 向当前路由器要一个带路径前缀的注册器。已登记的路由保持不变。返回值是注册器，不是应用自身。
     *
     * @param string $prefix 交给路由器的路径前缀。
     * @return RouteRegistrar 路由器返回的注册器。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $api = $app->prefix('/api');
     */
    public function prefix(string $prefix): RouteRegistrar
    {
        return $this->router->prefix($prefix);
    }
    /**
     * 向当前路由器要一个会给后续路由带上这些中间件的注册器。已登记的路由保持不变。
     *
     * @param mixed ...$middleware 按传入顺序交给路由器的中间件。
     * @return RouteRegistrar 路由器返回的注册器。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $json = $app->withMiddleware(\Loom\Http\Middleware\ParseJsonInput::class);
     */
    public function withMiddleware(mixed ...$middleware): RouteRegistrar
    {
        return $this->router->withMiddleware(...$middleware);
    }
    /**
     * 让路由器打开一个分组，前缀和中间件原样传给它。分组回调实际收到的是当前 Application，路由器传进闭包的注册器不会交给回调。
     * 回调结束后返回当前应用。分组被路由器拒绝时，异常在返回前抛出。
     *
     * @param string $prefix 这一组的路径前缀。
     * @param callable $register 分组内的注册回调，参数是当前 Application。
     * @param array $middleware 这一组追加的中间件。默认空数组。
     * @return self 当前 Application。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $app->group('/api', static function (Application $app): void { $app->get('/ping', fn (Request $request) => Response::json(['ok' => true])); });
     */
    public function group(string $prefix, callable $register, array $middleware = []): self
    {
        $this->router->group($prefix, function ($routes) use ($register): void {
            $register($this);
        }, $middleware);
        return $this;
    }
    /**
     * 把命名路由和参数交给当前路由器生成路径，并返回它的结果。
     * 名称不存在或参数缺失、多余、为空、含斜杠时，异常来自路由器。
     *
     * @param string $name 已登记的路由名。
     * @param array $parameters 路径占位符到值的映射。默认空数组。
     * @return string 路由器生成的路径，不含域名。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $path = $app->url('items.show', ['id' => 42]);
     */
    public function url(string $name, array $parameters = []): string
    {
        return $this->router->url($name, $parameters);
    }
    /**
     * 按给定的 HTTP 方法把一条路由交给当前路由器，并返回应用自身。
     * 方法、路径或名称不合法时，异常由路由器抛出。
     *
     * @param string $method 交给路由器的 HTTP 方法。
     * @param string $path 这条路由的路径。
     * @param mixed $action 这条路由的处理器，原样交给路由器。
     * @param string $name 路由名。默认空字符串，表示不命名。
     * @param array $middleware 只作用于这条路由的中间件。默认空数组。
     * @return self 当前 Application。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $app->route('GET', '/items/{id}', $handler, 'items.show');
     */
    public function route(string $method, string $path, mixed $action, string $name = '', array $middleware = []): self
    {
        $this->router->route($method, $path, $action, $name, $middleware);
        return $this;
    }
    /**
     * 把一条 GET 路由交给当前应用的路由器登记，并返回应用自身以便继续注册。
     * 路径重叠或路由名重复时，异常由路由器抛出。
     *
     * @param string $path 要登记的 GET 路径。
     * @param mixed $action 该路径的处理器，原样交给路由器。
     * @param string $name 路由名。默认空字符串，表示不命名。
     * @param array $middleware 只作用于这条路由的中间件。默认空数组。
     * @return self 当前 Application。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $app->get('/items', fn (Request $request) => Response::json(['ok' => true]));
     */
    public function get(string $path, mixed $action, string $name = '', array $middleware = []): self
    {
        $this->router->get($path, $action, $name, $middleware);
        return $this;
    }
    /**
     * 把一条 POST 路由交给当前应用的路由器登记，并返回应用自身以便继续注册。
     * 路径重叠或路由名重复时，异常由路由器抛出。
     *
     * @param string $path 要登记的 POST 路径。
     * @param mixed $action 该路径的处理器，原样交给路由器。
     * @param string $name 路由名。默认空字符串，表示不命名。
     * @param array $middleware 只作用于这条路由的中间件。默认空数组。
     * @return self 当前 Application。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $app->post('/items', fn (Request $request) => Response::json(['ok' => true]));
     */
    public function post(string $path, mixed $action, string $name = '', array $middleware = []): self
    {
        $this->router->post($path, $action, $name, $middleware);
        return $this;
    }
    /**
     * 把一条 PUT 路由交给当前应用的路由器登记，并返回应用自身以便继续注册。
     * 路径重叠或路由名重复时，异常由路由器抛出。
     *
     * @param string $path 要登记的 PUT 路径。
     * @param mixed $action 该路径的处理器，原样交给路由器。
     * @param string $name 路由名。默认空字符串，表示不命名。
     * @param array $middleware 只作用于这条路由的中间件。默认空数组。
     * @return self 当前 Application。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $app->put('/items', fn (Request $request) => Response::json(['ok' => true]));
     */
    public function put(string $path, mixed $action, string $name = '', array $middleware = []): self
    {
        $this->router->put($path, $action, $name, $middleware);
        return $this;
    }
    /**
     * 把一条 PATCH 路由交给当前应用的路由器登记，并返回应用自身以便继续注册。
     * 路径重叠或路由名重复时，异常由路由器抛出。
     *
     * @param string $path 要登记的 PATCH 路径。
     * @param mixed $action 该路径的处理器，原样交给路由器。
     * @param string $name 路由名。默认空字符串，表示不命名。
     * @param array $middleware 只作用于这条路由的中间件。默认空数组。
     * @return self 当前 Application。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $app->patch('/items', fn (Request $request) => Response::json(['ok' => true]));
     */
    public function patch(string $path, mixed $action, string $name = '', array $middleware = []): self
    {
        $this->router->patch($path, $action, $name, $middleware);
        return $this;
    }
    /**
     * 把一条 DELETE 路由交给当前应用的路由器登记，并返回应用自身以便继续注册。
     * 路径重叠或路由名重复时，异常由路由器抛出。
     *
     * @param string $path 要登记的 DELETE 路径。
     * @param mixed $action 该路径的处理器，原样交给路由器。
     * @param string $name 路由名。默认空字符串，表示不命名。
     * @param array $middleware 只作用于这条路由的中间件。默认空数组。
     * @return self 当前 Application。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $app->delete('/items', fn (Request $request) => Response::json(['ok' => true]));
     */
    public function delete(string $path, mixed $action, string $name = '', array $middleware = []): self
    {
        $this->router->delete($path, $action, $name, $middleware);
        return $this;
    }
    /**
     * 把一条 HEAD 路由交给当前应用的路由器登记，并返回应用自身以便继续注册。
     * 路径重叠或路由名重复时，异常由路由器抛出。
     *
     * @param string $path 要登记的 HEAD 路径。
     * @param mixed $action 该路径的处理器，原样交给路由器。
     * @param string $name 路由名。默认空字符串，表示不命名。
     * @param array $middleware 只作用于这条路由的中间件。默认空数组。
     * @return self 当前 Application。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $app->head('/items', fn (Request $request) => Response::json(['ok' => true]));
     */
    public function head(string $path, mixed $action, string $name = '', array $middleware = []): self
    {
        $this->router->head($path, $action, $name, $middleware);
        return $this;
    }
    /**
     * 把一条 OPTIONS 路由交给当前应用的路由器登记，并返回应用自身以便继续注册。
     * 路径重叠或路由名重复时，异常由路由器抛出。
     *
     * @param string $path 要登记的 OPTIONS 路径。
     * @param mixed $action 该路径的处理器，原样交给路由器。
     * @param string $name 路由名。默认空字符串，表示不命名。
     * @param array $middleware 只作用于这条路由的中间件。默认空数组。
     * @return self 当前 Application。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $app->options('/items', fn (Request $request) => Response::json(['ok' => true]));
     */
    public function options(string $path, mixed $action, string $name = '', array $middleware = []): self
    {
        $this->router->options($path, $action, $name, $middleware);
        return $this;
    }
    /**
     * 把一条 WebSocket 升级路由交给当前路由器，并返回应用自身。
     * 路径或方法不符合升级路由的要求时，异常由路由器抛出。
     *
     * @param string $path 升级路径。
     * @param mixed $action 升级之后的处理器，原样交给路由器。
     * @param string $name 路由名。默认空字符串，表示不命名。
     * @param array $middleware 只作用于这条路由的中间件。默认空数组。
     * @return self 当前 Application。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $app->websocket('/socket', $socketHandler);
     */
    public function websocket(string $path, mixed $action, string $name = '', array $middleware = []): self
    {
        $this->router->websocket($path, $action, $name, $middleware);
        return $this;
    }
}
