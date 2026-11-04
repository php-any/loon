<?php

declare(strict_types=1);

namespace Loom\Grpc;

/**
 * 应用显式登记 gRPC 路由的契约。返回值供 Dispatcher 在生成清单之前使用，已有的键不会被清单覆盖。
 *
 * 构造参数：无，本类型未声明构造参数。
 *
 * 返回值：接口不能直接实例化。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $routes = $provider->routes();
 */
interface RouteProvider
{
    /**
     * 返回本提供者显式登记的 RPC。键是完整 RPC 路径，值是服务类名和方法名。本方法不读取生成清单。
     *
     * 参数：无。
     *
     * @return array<string, array{class-string, string}> 路径到 [服务类名, 方法名] 的映射。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $routes = $provider->routes();
     */
    public function routes(): array;
}
