<?php

declare(strict_types=1);

namespace Loom\Attributes;

/**
 * 声明类或方法上的路由元数据，可重复使用；类级路由使用 handler 指定方法。
 *
 * 构造参数：
 * - $method（string）：HTTP 方法；WebSocket 路由必须为 GET。
 * - $path（string）：相对控制器前缀的 / 开头路由路径，可含 {id}。
 * - $name（string）：可选路由名称，空字符串表示不命名。默认值为 ''。
 * - $middleware（array）：追加到控制器级中间件之后的路由中间件列表。默认值为 []。
 * - $handler（string）：类级路由调用的方法名；方法级声明使用所标注的方法。默认值为 '__invoke'。
 * - $websocket（bool）：是否按 WebSocket 路由处理。默认值为 false。
 *
 * 返回值：使用 new 构造时得到 Route 实例；注册到容器时由容器管理实例的创建与复用。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example #[\Loom\Attributes\Route('GET', '/items/{id}', 'items.show')] public function show(\Loom\Request $request): \Loom\Response { return \Loom\Response::json(['id' => $request->parameter('id')]); }
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
     * 初始化 Route 实例及其依赖。声明类或方法上的路由元数据，可重复使用；类级路由使用 handler 指定方法。
     *
     * @param string $method HTTP 方法；WebSocket 路由必须为 GET。
     * @param string $path 相对控制器前缀的 / 开头路由路径，可含 {id}。
     * @param string $name 可选路由名称，空字符串表示不命名。默认值为 ''。
     * @param array $middleware 追加到控制器级中间件之后的路由中间件列表。默认值为 []。
     * @param string $handler 类级路由调用的方法名；方法级声明使用所标注的方法。默认值为 '__invoke'。
     * @param bool $websocket 是否按 WebSocket 路由处理。默认值为 false。
     * @return void 无直接返回值；通过 new 表达式取得初始化后的实例。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example #[\Loom\Attributes\Route('GET', '/items/{id}', 'items.show')] public function show(\Loom\Request $request): \Loom\Response { return \Loom\Response::json(['id' => $request->parameter('id')]); }
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
