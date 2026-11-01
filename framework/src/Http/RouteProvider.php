<?php

declare(strict_types=1);

namespace Loom\Http;

/**
 * 声明应用 HTTP 路由注册契约，由应用实现并通过容器绑定，集中安装业务路由。
 *
 * 构造参数：无，本类型未声明构造参数。
 *
 * 返回值：接口不能直接实例化；由实现类提供本契约，操作结果见各方法的返回值。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $provider->register($router);
 */
interface RouteProvider
{
    /**
     * 向给定路由器注册应用路由及中间件。
     *
     * @param \Loom\Router $routes 接收应用路由定义的 Router 实例。
     * @return void 无返回值，修改传入路由器的注册表。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $provider->register($router);
     */
    public function register(\Loom\Router $routes): void;
}
