<?php

declare(strict_types=1);

namespace Loom\Grpc;

/**
 * 把显式路由和生成清单合成一张表，再从容器取出服务并调用方法。显式键优先。RpcException 变成错误信封。
 *
 * 构造参数：
 * - $services（Container）：按路由里的类名 get 服务。
 *
 * 返回值：使用 new 构造时得到尚未处理调用的 Dispatcher。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $dispatcher = new Dispatcher($services);
 */
final class Dispatcher
{
    private \Loom\Container $services;


    /**
     * 保存用来解析 RPC 服务的容器。构造时不读清单，也不登记路由。
     *
     * @param \Loom\Container $services 按路由目标类名 get 的容器。
     * @return void 无直接返回值。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $dispatcher = new Dispatcher($services);
     */
    public function __construct(\Loom\Container $services)
    {
        $this->services = $services;

    }

    /**
     * 清单文件存在时，把其中尚未出现在 routes 里的方法补上；调用方已经给出的键保持不变。清单 JSON 不合法时异常来自 json_decode。
     * 然后按 envelope 的 method 查找目标，从容器 get 服务并调用对应方法。成功时返回 data。
     * 方法未登记时抛出状态为 unimplemented 的 RpcException。RpcException 收成 error，code 用异常上的 status。其他异常不在这里捕获。
     *
     * @param array $routes 完整 RPC 路径到 [类名, 方法名]。已有项不会被清单覆盖。
     * @param string $manifestPath 生成清单路径。文件不存在时只用 routes。
     * @param array $envelope 含 method 和 request 的调用信封。
     * @return array 成功时为 data；RpcException 时为 error，其中 code 是状态、message 是异常消息。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $result = $dispatcher->handle($routes, $manifestPath, ['method' => '/billing.Invoice/Get', 'request' => []]);
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
