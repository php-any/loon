<?php

declare(strict_types=1);

namespace Loom;

/**
 * 声明不依赖容器的路由匹配接口，允许替换 Router 的匹配实现。
 *
 * 构造参数：无，本类型未声明构造参数。
 *
 * 返回值：接口不能直接实例化；由实现类提供本契约，操作结果见各方法的返回值。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $match = $matcher->match('GET', '/items/42');
 */
interface RouteMatcher
{
    /**
     * 按方法与路径查询路由定义，不构造控制器或中间件。
     *
     * @param string $method HTTP 方法字符串，例如 GET 或 POST。
     * @param string $path 待匹配的请求路径，例如 /items。
     * @return RouteMatch 包含匹配状态和路由数据的 RouteMatch。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $match = $matcher->match('GET', '/items/42');
     */
    public function match(string $method, string $path): RouteMatch;
}
