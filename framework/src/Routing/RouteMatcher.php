<?php

declare(strict_types=1);

namespace Loom;

/**
 * 只声明按方法和路径查找路由的能力。实现不需要在匹配时使用容器。
 *
 * 构造参数：无，本类型未声明构造参数。
 *
 * 返回值：接口不能直接实例化。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $match = $matcher->match('GET', '/items/42');
 */
interface RouteMatcher
{
    /**
     * 按方法和路径查找路由定义。实现不应在匹配时构造控制器或中间件。
     *
     * @param string $method HTTP 方法，例如 GET 或 POST。
     * @param string $path 请求路径，例如 /items/42。
     * @return RouteMatch 命中、未找到或方法不允许的匹配结果。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $match = $matcher->match('GET', '/items/42');
     */
    public function match(string $method, string $path): RouteMatch;
}
