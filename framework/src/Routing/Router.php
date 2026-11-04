<?php

declare(strict_types=1);

namespace Loom;

/**
 * 保存并校验路由定义，按方法和路径匹配，也能用路由名生成路径。匹配时不解析服务。
 *
 * 构造参数：无，本类型未声明构造参数。
 *
 * 返回值：使用 new 构造时得到 Router 实例。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $router = new Router();
 */
final class Router implements RouteMatcher
{
    private array $routes = [];
    private string $prefix = '';
    private array $named = [];
    private array $groupMiddleware = [];
    /**
     * 检查中间件定义后，返回一个不带前缀、只带这些中间件的注册器。定义不合法时抛出 LogicException。不改已登记的路由。
     *
     * @param mixed ...$middleware 交给后续注册使用的中间件。可以是类名、Middleware 实例或可调用对象。
     * @return RouteRegistrar 前缀为空、中间件为这些值的新注册器。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $jsonRoutes = $router->withMiddleware(\Loom\Http\Middleware\ParseJsonInput::class);
     */
    public function withMiddleware(mixed ...$middleware): RouteRegistrar
    {
        Pipeline::validate($middleware);
        return new RouteRegistrar($this, '', $middleware);
    }
    /**
     * 返回一个带有给定前缀的注册器。前缀末尾斜杠会被去掉。不改已登记的路由，也不改当前分组前缀。
     *
     * @param string $prefix 注册器使用的路径前缀，例如 /api。
     * @return RouteRegistrar 使用该前缀、中间件为空的新注册器。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $api = $router->prefix('/api');
     */
    public function prefix(string $prefix): RouteRegistrar
    {
        return new RouteRegistrar($this, rtrim($prefix, '/'));
    }
    /**
     * 登记一条 GET 路由。控制器留到分发时解析。同一方法下路径形状重叠，或路由名重复时，抛出 LogicException。
     *
     * @param string $path 以 / 开头的路径，可含 {id} 这样的整段占位符，不含查询串或片段。
     * @param mixed $action 类名、可调用对象，或 [控制器, 方法名]。
     * @param string $name 路由名。空字符串表示不命名。默认空字符串。
     * @param array $middleware 只加在这条路由上的中间件定义。默认空数组。
     * @return self 当前路由器。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $router->get('/items', fn (Request $request) => Response::json(['ok' => true]));
     */
    public function get(string $path, mixed $action, string $name = '', array $middleware = []): self
    {
        return $this->route('GET', $path, $action, $name, $middleware);
    }
    /**
     * 登记一条 POST 路由。控制器留到分发时解析。同一方法下路径形状重叠，或路由名重复时，抛出 LogicException。
     *
     * @param string $path 以 / 开头的路径，可含 {id} 这样的整段占位符，不含查询串或片段。
     * @param mixed $action 类名、可调用对象，或 [控制器, 方法名]。
     * @param string $name 路由名。空字符串表示不命名。默认空字符串。
     * @param array $middleware 只加在这条路由上的中间件定义。默认空数组。
     * @return self 当前路由器。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $router->post('/items', fn (Request $request) => Response::json(['ok' => true]));
     */
    public function post(string $path, mixed $action, string $name = '', array $middleware = []): self
    {
        return $this->route('POST', $path, $action, $name, $middleware);
    }
    /**
     * 登记一条 PATCH 路由。控制器留到分发时解析。同一方法下路径形状重叠，或路由名重复时，抛出 LogicException。
     *
     * @param string $path 以 / 开头的路径，可含 {id} 这样的整段占位符，不含查询串或片段。
     * @param mixed $action 类名、可调用对象，或 [控制器, 方法名]。
     * @param string $name 路由名。空字符串表示不命名。默认空字符串。
     * @param array $middleware 只加在这条路由上的中间件定义。默认空数组。
     * @return self 当前路由器。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $router->patch('/items', fn (Request $request) => Response::json(['ok' => true]));
     */
    public function patch(string $path, mixed $action, string $name = '', array $middleware = []): self
    {
        return $this->route('PATCH', $path, $action, $name, $middleware);
    }
    /**
     * 登记一条 DELETE 路由。控制器留到分发时解析。同一方法下路径形状重叠，或路由名重复时，抛出 LogicException。
     *
     * @param string $path 以 / 开头的路径，可含 {id} 这样的整段占位符，不含查询串或片段。
     * @param mixed $action 类名、可调用对象，或 [控制器, 方法名]。
     * @param string $name 路由名。空字符串表示不命名。默认空字符串。
     * @param array $middleware 只加在这条路由上的中间件定义。默认空数组。
     * @return self 当前路由器。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $router->delete('/items', fn (Request $request) => Response::json(['ok' => true]));
     */
    public function delete(string $path, mixed $action, string $name = '', array $middleware = []): self
    {
        return $this->route('DELETE', $path, $action, $name, $middleware);
    }
    /**
     * 临时接上前缀和中间件，执行回调，再恢复进入前的前缀和分组中间件。回调抛出时也会恢复。中间件定义不合法时先抛出 LogicException，分组状态不变。
     *
     * @param string $prefix 追加到当前前缀后的路径。末尾斜杠会被去掉。
     * @param callable $register 接收本路由器并登记路由的回调。
     * @param array $middleware 只在回调期间追加的中间件。默认空数组。
     * @return self 当前路由器。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $router->group('/api', static function (Router $r): void { $r->get('/ping', fn (Request $q) => Response::json(['ok' => true])); });
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
     * 用命名路由的路径模板生成 URL，占位符经 rawurlencode 替换。名称不存在、参数缺失、值为空、值含斜杠或有多余参数时抛出 LogicException。结果不含域名和查询串。
     *
     * @param string $name 已登记的路由名。
     * @param array $parameters 占位符到值的映射，必须正好覆盖模板中的占位符。默认空数组。
     * @return string 以 / 开头的路径。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $url = $router->url('items.show', ['id' => 42]);
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
     * 登记一条普通 HTTP 路由，不标记为 WebSocket。方法和路径不合法、名称重复或路径重叠时抛出 LogicException。
     *
     * @param string $method 方法名，大小写不限。只接受 GET、HEAD、POST、PUT、PATCH、DELETE、OPTIONS。
     * @param string $path 以 / 开头的路径，可含整段占位符，不能带查询串或片段。
     * @param mixed $action 类名、可调用对象，或 [控制器, 方法名]。
     * @param string $name 路由名。空字符串表示不命名。默认空字符串。
     * @param array $middleware 追加到当前分组中间件之后的中间件。默认空数组。
     * @return self 当前路由器。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $router->route('GET', '/items/{id}', $handler, 'items.show');
     */
    public function route(string $method, string $path, mixed $action, string $name = '', array $middleware = []): self
    {
        return $this->addRoute($method, $path, $action, $name, $middleware, false);
    }
    /**
     * 校验中间件、处理器、方法和路径后写入路由表，路径会先接上当前前缀。名称重复、方法不支持、路径不是以 / 开头、含有 ? 或 #、占位符不合法或重复时抛出 LogicException。与同一方法、段数相同的已有路由比较时，只要没有任何一段是两个不同的字面量，就视为重叠并抛出 LogicException。
     *
     * @param string $method HTTP 方法，检查前转为大写。
     * @param string $path 相对当前前缀的路径，必须以 / 开头。
     * @param mixed $action 交给 ActionResolver::validate 的处理器定义。
     * @param string $name 路由名。空字符串不写入命名表。
     * @param array $middleware 与当前分组中间件合并的本路由中间件。
     * @param bool $websocket 为 true 时这条记录按 WebSocket 路由保存。
     * @return self 当前路由器。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
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
     * 登记一条方法固定为 GET 的 WebSocket 路由。路径和名称规则与普通路由相同，重叠或重名时抛出 LogicException。处理器稍后接收请求和原生连接。
     *
     * @param string $path 以 / 开头的路径，可含整段占位符。
     * @param mixed $action 升级后调用的处理器定义。类名、可调用对象，或 [控制器, 方法名]。
     * @param string $name 路由名。空字符串表示不命名。默认空字符串。
     * @param array $middleware 这条路由额外的中间件。默认空数组。
     * @return self 当前路由器。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $router->websocket('/socket', $socketHandler);
     */
    public function websocket(string $path, mixed $action, string $name = '', array $middleware = []): self
    {
        return $this->addRoute('GET', $path, $action, $name, $middleware, true);
    }
    /**
     * 登记一条 PUT 路由。控制器留到分发时解析。同一方法下路径形状重叠，或路由名重复时，抛出 LogicException。
     *
     * @param string $path 以 / 开头的路径，可含 {id} 这样的整段占位符，不含查询串或片段。
     * @param mixed $action 类名、可调用对象，或 [控制器, 方法名]。
     * @param string $name 路由名。空字符串表示不命名。默认空字符串。
     * @param array $middleware 只加在这条路由上的中间件定义。默认空数组。
     * @return self 当前路由器。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $router->put('/items', fn (Request $request) => Response::json(['ok' => true]));
     */
    public function put(string $path, mixed $action, string $name = '', array $middleware = []): self
    {
        return $this->route('PUT', $path, $action, $name, $middleware);
    }
    /**
     * 登记一条 HEAD 路由。控制器留到分发时解析。同一方法下路径形状重叠，或路由名重复时，抛出 LogicException。
     *
     * @param string $path 以 / 开头的路径，可含 {id} 这样的整段占位符，不含查询串或片段。
     * @param mixed $action 类名、可调用对象，或 [控制器, 方法名]。
     * @param string $name 路由名。空字符串表示不命名。默认空字符串。
     * @param array $middleware 只加在这条路由上的中间件定义。默认空数组。
     * @return self 当前路由器。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $router->head('/items', fn (Request $request) => Response::json(['ok' => true]));
     */
    public function head(string $path, mixed $action, string $name = '', array $middleware = []): self
    {
        return $this->route('HEAD', $path, $action, $name, $middleware);
    }
    /**
     * 登记一条 OPTIONS 路由。控制器留到分发时解析。同一方法下路径形状重叠，或路由名重复时，抛出 LogicException。
     *
     * @param string $path 以 / 开头的路径，可含 {id} 这样的整段占位符，不含查询串或片段。
     * @param mixed $action 类名、可调用对象，或 [控制器, 方法名]。
     * @param string $name 路由名。空字符串表示不命名。默认空字符串。
     * @param array $middleware 只加在这条路由上的中间件定义。默认空数组。
     * @return self 当前路由器。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $router->options('/items', fn (Request $request) => Response::json(['ok' => true]));
     */
    public function options(string $path, mixed $action, string $name = '', array $middleware = []): self
    {
        return $this->route('OPTIONS', $path, $action, $name, $middleware);
    }
    /**
     * 按路径段查找已登记路由，不创建控制器。HEAD 没有独立路由时改用同路径的 GET。没有路由对上该路径时返回 404，路径上只有其它方法时返回 405 及允许的方法。空的占位段不算命中。
     *
     * @param string $method 请求方法，比较前转为大写。
     * @param string $path 请求路径，按 / 拆成路径段后与路由比较。
     * @return RouteMatch 状态为 200、404 或 405。命中时带处理器定义、参数和中间件。
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
