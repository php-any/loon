<?php

declare(strict_types=1);

namespace Loom\Grpc;

/**
 * gRPC 方法表：Application::mapGrpc() 登记的路由优先，生成清单 generated/grpc/routes.json 补齐其余方法。
 * 每次调用从当前作用域取出服务并调用方法，RpcException 变成错误信封。
 *
 * @example $result = $dispatcher->handle($scope, '/billing.Invoice/Get', ['id' => 1]);
 */
final class Dispatcher
{
    /** @var array<string, array{0: string, 1: string}> */
    private array $routes = [];
    private string $manifestPath;

    public function __construct(\Loom\Config $config)
    {
        $this->manifestPath = $config->root() . '/generated/grpc/routes.json';
    }

    /**
     * 登记一个方法。重复登记时后者覆盖前者。
     *
     * @param string $method 完整 RPC 路径，如 /billing.Invoice/Get。
     * @param array{0: string, 1: string} $handler [服务类, 方法名]。
     */
    public function map(string $method, array $handler): self
    {
        if (!str_starts_with($method, '/') || !isset($handler[0], $handler[1]) || !is_string($handler[0]) || !is_string($handler[1])) {
            throw new \InvalidArgumentException('gRPC route requires /package.Service/Method and [class, method]');
        }
        $this->routes[$method] = [$handler[0], $handler[1]];
        return $this;
    }

    public function manifestPath(): string
    {
        return $this->manifestPath;
    }

    /**
     * @return array<string, array{0: string, 1: string}> 显式路由加清单路由。清单 JSON 不合法时抛出 JsonException。
     */
    public function routes(): array
    {
        $routes = $this->routes;
        if (is_file($this->manifestPath)) {
            $manifest = json_decode(file_get_contents($this->manifestPath), true, 512, JSON_THROW_ON_ERROR);
            foreach ($manifest['files'] as $file) {
                foreach ($file['routes'] as $method => $target) {
                    $routes[$method] ??= $target;
                }
            }
        }
        return $routes;
    }

    /**
     * 找到方法对应的服务，从作用域取出并调用。未登记时返回 unimplemented。其他异常不在这里捕获。
     *
     * @return array 成功时为 ['data' => ...]；RpcException 时为 ['error' => ['code' => 状态, 'message' => 消息]]。
     */
    public function handle(\Loom\ServiceResolver $services, string $method, array $request): array
    {
        try {
            $routes = $this->routes();
            if (!isset($routes[$method])) {
                throw new RpcException('unimplemented', 'RPC handler is not registered');
            }
            [$class, $action] = $routes[$method];
            return ['data' => $services->get($class)->$action($request)];
        } catch (RpcException $error) {
            return ['error' => ['code' => $error->status, 'message' => $error->getMessage()]];
        }
    }
}
