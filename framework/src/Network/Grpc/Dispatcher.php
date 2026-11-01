<?php

declare(strict_types=1);

namespace Loom\Grpc;

/**
 * 合并显式与生成的 RPC 路由并调用容器中的服务，显式路由优先，业务异常转为错误信封。
 *
 * 构造参数：
 * - $services（\Loom\Container）：提供依赖解析和实例注册的服务容器。
 *
 * 返回值：使用 new 构造时得到 Dispatcher 实例；注册到容器时由容器管理实例的创建与复用。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $result = $dispatcher->handle($routes, $manifestPath, ['method' => $rpcPath, 'request' => $requestData]);
 */
final class Dispatcher
{
    private \Loom\Container $services;


    /**
     * 初始化 Dispatcher 实例及其依赖。合并显式与生成的 RPC 路由并调用容器中的服务，显式路由优先，业务异常转为错误信封。
     *
     * @param \Loom\Container $services 提供依赖解析和实例注册的服务容器。
     * @return void 无直接返回值；通过 new 表达式取得初始化后的实例。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $result = $dispatcher->handle($routes, $manifestPath, ['method' => $rpcPath, 'request' => $requestData]);
     */
    public function __construct(\Loom\Container $services)
    {
        $this->services = $services;

    }

    /**
     * 加载清单补齐路由，解析服务并调用指定方法；捕获 RpcException 形成命名错误。
     *
     * @param array $routes RPC 完整路径到 [服务类名, 方法名] 的映射，优先于生成清单。
     * @param string $manifestPath 可选生成清单文件路径；文件不存在时仅使用显式路由。
     * @param array $envelope 包含 method 完整 RPC 路径及 request 解码值的数组。
     * @return array 成功返回 ['data' => 结果]；业务错误返回 ['error' => ['code' => 状态, 'message' => 说明]]。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $result = $dispatcher->handle($routes, $manifestPath, $envelope);
     */
    public function handle(array $routes, string $manifestPath, array $envelope): array
    {
        if (is_file($manifestPath)) {
            $manifest = json_decode(file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
            foreach ($manifest['files'] as $file) {
                foreach ($file['routes'] as $method => $target) {
                    if (!isset($routes[$method])) {
                        $routes[$method] = $target;
                    }
                }
            }
        }

        try {
            $method = $envelope['method'];
            if (!isset($routes[$method])) {
                throw new RpcException('unimplemented', 'RPC handler is not registered');
            }
            $target = $routes[$method];
            $class = $target[0];
            $service = $this->services->get($class);
            $action = $target[1];
            return ['data' => $service->$action($envelope['request'])];
        } catch (RpcException $error) {
            return ['error' => ['code' => $error->status, 'message' => $error->getMessage()]];
        }
    }
}
