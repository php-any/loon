<?php

declare(strict_types=1);

namespace Loom\Attributes;

/**
 * 可重复标在类或方法上的路由声明。发现器在类上使用 handler 里的方法名，在方法上则改用被标注的方法。
 *
 * 构造参数：
 * - $method（string）：写入 method 的 HTTP 方法。
 * - $path（string）：写入 path 的路径，发现时再与控制器前缀拼接。
 * - $name（string）：写入 name。默认空字符串。
 * - $middleware（array）：写入 middleware。默认空数组。
 * - $handler（string）：写入 handler，供类级路由选用。默认 __invoke。
 * - $websocket（bool）：写入 websocket。默认 false。
 *
 * 返回值：使用 new 构造时得到只保存这些字段的 Route。构造时不检查方法或路径。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $route = new Route('GET', '/items/{id}', 'items.show');
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
final class Route
{
    public string $method;
    public string $path;
    public string $name;
    public array $middleware;
    public string $handler;
    public bool $websocket;
    /**
     * 把方法、路径、名称、中间件、处理方法和是否为 WebSocket 写到对应的公开属性。取值原样保存。
     *
     * @param string $method HTTP 方法名。
     * @param string $path 相对控制器前缀的路径。
     * @param string $name 路由名。默认空字符串。
     * @param array $middleware 这条路由自己的中间件。默认空数组。
     * @param string $handler 类级注解要调用的方法名。默认 __invoke。
     * @param bool $websocket true 表示这条路由按 WebSocket 登记。默认 false。
     * @return void 无直接返回值。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $route = new Route('GET', '/items/{id}', 'items.show');
     */
    public function __construct(string $method, string $path, string $name = '', array $middleware = [], string $handler = '__invoke', bool $websocket = false)
    {
        $this->method = $method;
        $this->path = $path;
        $this->name = $name;
        $this->middleware = $middleware;
        $this->handler = $handler;
        $this->websocket = $websocket;
    }
}
