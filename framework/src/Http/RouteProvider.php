<?php

declare(strict_types=1);

namespace Loom\Http;

/**
 * 应用实现此接口，把业务路由集中登记到传入的 Router。
 *
 * 构造参数：无，本类型未声明构造参数。
 *
 * 返回值：接口不能直接实例化。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $provider->register($router);
 */
interface RouteProvider
{
    /**
     * 把本应用的路由和中间件登记到传入的路由器上。实现直接修改该路由器。
     *
     * @param \Loom\Router $routes 接收路由定义的 Router。
     * @return void 无返回值。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $provider->register($router);
     */
    public function register(\Loom\Router $routes): void;
}
