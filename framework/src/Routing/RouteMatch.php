<?php

declare(strict_types=1);

namespace Loom;

// 路由结果仅包含定义；服务解析由内核负责。
/**
 * 纯数据路由匹配结果，携带状态、处理器定义、参数、中间件和允许方法；不负责实例化处理器。
 *
 * 构造参数：
 * - $status（int）：匹配结果状态：200 成功、404 路径不存在、405 方法不允许。
 * - $action（mixed）：待解析的处理器定义；未匹配时为 null。默认值为 null。
 * - $parameters（array）：匹配出的路由参数映射。默认值为 []。
 * - $middleware（array）：匹配路由需要执行的中间件列表。默认值为 []。
 * - $websocket（bool）：是否为 WebSocket 升级路由。默认值为 false。
 * - $allowed（array）：405 响应使用的允许 HTTP 方法列表。默认值为 []。
 *
 * 返回值：使用 new 构造时得到 RouteMatch 实例；注册到容器时由容器管理实例的创建与复用。
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
     * 初始化 RouteMatch 实例及其依赖。纯数据路由匹配结果，携带状态、处理器定义、参数、中间件和允许方法；不负责实例化处理器。
     *
     * @param int $status 匹配结果状态：200 成功、404 路径不存在、405 方法不允许。
     * @param mixed $action 待解析的处理器定义；未匹配时为 null。默认值为 null。
     * @param array $parameters 匹配出的路由参数映射。默认值为 []。
     * @param array $middleware 匹配路由需要执行的中间件列表。默认值为 []。
     * @param bool $websocket 是否为 WebSocket 升级路由。默认值为 false。
     * @param array $allowed 405 响应使用的允许 HTTP 方法列表。默认值为 []。
     * @return void 无直接返回值；通过 new 表达式取得初始化后的实例。
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
