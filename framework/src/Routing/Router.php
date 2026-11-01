<?php

declare(strict_types=1);

namespace Loom;

/**
 * 保存并校验路由定义，匹配方法和路径并生成命名 URL；匹配时不解析服务。
 *
 * 构造参数：无，本类型未声明构造参数。
 *
 * 返回值：使用 new 构造时得到 Router 实例；注册到容器时由容器管理实例的创建与复用。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $router = new Router(); $router->get('/ping', fn (Request $r) => Response::json(['ok' => true]), 'ping');
 */
final class Router implements RouteMatcher
{
    private array $routes = [];
    private string $prefix = '';
    private array $named = [];
    private array $groupMiddleware = [];
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
        Pipeline::validate($middleware);
        return new RouteRegistrar($this, '', $middleware);
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
        return new RouteRegistrar($this, rtrim($prefix, '/'));
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
        return $this->route('GET', $path, $action, $name, $middleware);
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
        return $this->route('POST', $path, $action, $name, $middleware);
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
        return $this->route('PATCH', $path, $action, $name, $middleware);
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
        return $this->route('DELETE', $path, $action, $name, $middleware);
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
        Pipeline::validate($middleware);
        $oldMiddleware = $this->groupMiddleware;
        $this->groupMiddleware = array_merge($oldMiddleware, $middleware);
        $old = $this->prefix;
        $this->prefix .= rtrim($prefix, '/');
        try {
            $register($this);
        } finally {
            $this->prefix = $old;
            $this->groupMiddleware = $oldMiddleware;
        }
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
        if (!isset($this->named[$name])) {
            throw new \LogicException('Unknown route name: ' . $name);
        }
        $parts = explode('/', $this->named[$name]);
        foreach ($parts as $index => $part) {
            if ($part !== '' && $part[0] === '{') {
                $key = substr($part, 1, -1);
                if (!isset($parameters[$key]) || (string) $parameters[$key] === '' || strpos((string) $parameters[$key], '/') !== false) {
                    throw new \LogicException('Invalid URL parameter: ' . $key);
                }
                $parts[$index] = rawurlencode((string) $parameters[$key]);
                unset($parameters[$key]);
            }
        }
        if ($parameters !== []) {
            throw new \LogicException('Unknown URL parameters');
        }
        return implode('/', $parts);
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
        return $this->addRoute($method, $path, $action, $name, $middleware, false);
    }
    /**
     * 验证处理器、中间件、路径段及路由歧义，再保存路由记录。
     *
     * @param string $method HTTP 方法，可选 GET、HEAD、POST、PUT、PATCH、DELETE、OPTIONS。
     * @param string $path 以 / 开头的路由路径，可含 {id} 等完整路径段占位符；不包含查询串或片段。
     * @param mixed $action 控制器类名、可调用对象或 [控制器类名或实例, 方法名]；普通 HTTP 处理器须返回 Response。
     * @param string $name 命名路由标识；空字符串表示不命名。
     * @param array $middleware 中间件定义；列表按注册顺序执行，支持类名、Middleware 实例或回调。
     * @param bool $websocket 是否使用 WebSocket 升级处理模式。
     * @return self 当前 Router 实例。
     *
     * 内部调用示例（仅类内使用）：
     * @example $this->addRoute('GET', '/ping', $handler, 'ping', [], false);
     */
    private function addRoute(string $method, string $path, mixed $action, string $name, array $middleware, bool $websocket): self
    {
        Pipeline::validate($middleware);
        $path = $this->prefix . $path;
        if ($name !== '' && isset($this->named[$name])) {
            throw new \LogicException('Duplicate route name: ' . $name);
        }
        ActionResolver::validate($action);
        $method = strtoupper($method);
        if (!in_array($method, ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'], true)) {
            throw new \LogicException('Unsupported HTTP method');
        }
        if ($path === '' || $path[0] !== '/' || strpos($path, '?') !== false || strpos($path, '#') !== false) {
            throw new \LogicException('Route must be an absolute path');
        }
        $parts = explode('/', $path);
        $shape = [];
        $names = [];
        foreach ($parts as $part) {
            if (preg_match('/^\{([A-Za-z_][A-Za-z0-9_]*)\}$/D', $part, $match)) {
                if (in_array($match[1], $names, true)) {
                    throw new \LogicException('Duplicate route parameter');
                }
                $names[] = $match[1];
                $shape[] = '{}';
            } else {
                if (strpos($part, '{') !== false || strpos($part, '}') !== false) {
                    throw new \LogicException('Invalid route parameter');
                }
                $shape[] = $part;
            }
        }
        foreach ($this->routes as $route) {
            if ($route['method'] !== $method || count($route['shape']) !== count($shape)) {
                continue;
            }
            $overlap = true;
            foreach ($shape as $index => $part) {
                $other = $route['shape'][$index];
                if ($part !== '{}' && $other !== '{}' && $part !== $other) {
                    $overlap = false;
                }
            }
            if ($overlap) {
                throw new \LogicException('Ambiguous route: ' . $method . ' ' . $path);
            }
        }
        $this->routes[] = ['method' => $method, 'parts' => $parts, 'shape' => $shape, 'action' => $action, 'websocket' => $websocket, 'middleware' => array_merge($this->groupMiddleware, $middleware)];
        if ($name !== '') {
            $this->named[$name] = $path;
        }
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
        return $this->addRoute('GET', $path, $action, $name, $middleware, true);
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
        return $this->route('PUT', $path, $action, $name, $middleware);
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
        return $this->route('HEAD', $path, $action, $name, $middleware);
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
        return $this->route('OPTIONS', $path, $action, $name, $middleware);
    }
    /**
     * 匹配方法与路径；HEAD 优先独立路由，否则回退 GET；区分路径未找到与方法不允许。
     *
     * @param string $method HTTP 方法，可选 GET、HEAD、POST、PUT、PATCH、DELETE、OPTIONS。
     * @param string $path 以 / 开头的路由路径，可含 {id} 等完整路径段占位符；不包含查询串或片段。
     * @return RouteMatch 状态为 200、404 或 405 的 RouteMatch；成功时带处理器定义和参数。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $match = $router->match('GET', '/items/42');
     */
    public function match(string $method, string $path): RouteMatch
    {
        $allowed = [];
        $matches = [];
        $parts = explode('/', $path);
        foreach ($this->routes as $route) {
            if (count($parts) !== count($route['parts'])) {
                continue;
            }
            $parameters = [];
            $matched = true;
            foreach ($route['parts'] as $index => $part) {
                if ($route['shape'][$index] === '{}') {
                    if ($parts[$index] === '') {
                        $matched = false;
                        break;
                    }
                    $parameters[substr($part, 1, -1)] = $parts[$index];
                } elseif ($part !== $parts[$index]) {
                    $matched = false;
                    break;
                }
            }
            if (!$matched) {
                continue;
            }
            $allowed[] = $route['method'];
            if ($route['method'] === 'GET') {
                $allowed[] = 'HEAD';
            }
            $matches[$route['method']] = ['action' => $route['action'], 'websocket' => $route['websocket'], 'parameters' => $parameters, 'middleware' => $route['middleware']];
        }
        $method = strtoupper($method);
        if ($method === 'HEAD' && !isset($matches['HEAD'])) {
            $method = 'GET';
        }
        if (isset($matches[$method])) {
            $match = $matches[$method];
            return new RouteMatch(200, $match['action'], $match['parameters'], $match['middleware'], $match['websocket']);
        }
        return new RouteMatch($allowed === [] ? 404 : 405, null, [], [], false, array_values(array_unique($allowed)));
    }
}
