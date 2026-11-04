<?php

declare(strict_types=1);

namespace Loom;

// 路由结果仅包含定义；服务解析由内核负责。
/**
 * 一次路由匹配的纯数据结果。只保存状态、处理器定义、参数、中间件和允许的方法，不创建处理器。
 *
 * 构造参数：
 * - $status（int）：匹配状态。约定 200 为命中，404 为路径不存在，405 为方法不允许。
 * - $action（mixed）：待稍后解析的处理器定义。未命中时为 null。默认 null。
 * - $parameters（array）：路径占位符到字符串的映射。默认空数组。
 * - $middleware（array）：这条路由要执行的中间件定义。默认空数组。
 * - $websocket（bool）：命中的是否为 WebSocket 路由。默认 false。
 * - $allowed（array）：405 时列出的允许方法。默认空数组。
 *
 * 返回值：使用 new 构造时得到 RouteMatch 实例。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $notFound = new RouteMatch(404);
 */
final class RouteMatch
{
    public int $status;
    public mixed $action;
    public array $parameters;
    public array $middleware;
    public bool $websocket;
    public array $allowed;
    /**
     * 把状态、处理器定义、参数、中间件、WebSocket 标记和允许的方法原样记到公开属性上。不检查状态码，也不实例化处理器。
     *
     * @param int $status 匹配状态。约定 200 为命中，404 为路径不存在，405 为方法不允许。
     * @param mixed $action 待稍后解析的处理器定义。未命中时为 null。默认 null。
     * @param array $parameters 路径占位符到字符串的映射。默认空数组。
     * @param array $middleware 这条路由要执行的中间件定义。默认空数组。
     * @param bool $websocket 命中的是否为 WebSocket 路由。默认 false。
     * @param array $allowed 405 时列出的允许方法。默认空数组。
     * @return void 无直接返回值。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $notFound = new RouteMatch(404);
     */
    public function __construct(int $status, mixed $action = null, array $parameters = [], array $middleware = [], bool $websocket = false, array $allowed = [])
    {
        $this->status = $status;
        $this->action = $action;
        $this->parameters = $parameters;
        $this->middleware = $middleware;
        $this->websocket = $websocket;
        $this->allowed = $allowed;
    }
}
