<?php

declare(strict_types=1);

namespace Loom\Grpc;

/**
 * 声明应用 gRPC 路由提供契约，将完整 RPC 路径映射到服务类和方法。
 *
 * 构造参数：无，本类型未声明构造参数。
 *
 * 返回值：接口不能直接实例化；由实现类提供本契约，操作结果见各方法的返回值。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $routes = $provider->routes();
 */
interface RouteProvider
{
    /**
     * 提供应用显式注册的 RPC 处理器映射。
     *
     * 参数：无。
     *
     * @return array<string, array{class-string, string}> RPC 路径到 [服务完整类名, 方法名] 的关联数组。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $routes = $provider->routes(); // 例如 ['/billing.Invoice/Get' => [InvoiceService::class, 'get']]
     */
    public function routes(): array;
}
