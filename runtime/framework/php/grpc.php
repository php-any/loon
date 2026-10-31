<?php
declare(strict_types=1);

namespace Loom\Grpc;

final class RpcException extends \RuntimeException
{
    public string $status;

    public function __construct(string $status, string $message)
    {
        parent::__construct($message);
        $this->status = $status;
    }
}

final class Dispatcher
{
    public static function handle(array $routes, string $manifestPath): array
    {
        if (is_file($manifestPath)) {
            $manifest = json_decode(file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
            foreach ($manifest['files'] as $file) {
                foreach ($file['routes'] as $method => $target) {
                    if (!isset($routes[$method])) { $routes[$method] = $target; }
                }
            }
        }
        $envelope = json_decode(\Loom\message_envelope(), true, 512, JSON_THROW_ON_ERROR);
        try {
            $method = $envelope['method'];
            if (!isset($routes[$method])) {
                throw new RpcException('unimplemented', 'RPC handler is not registered');
            }
            $target = $routes[$method];
            $class = $target[0];
            $service = new $class();
            $action = $target[1];
            return ['data' => $service->$action($envelope['request'])];
        } catch (RpcException $error) {
            return ['error' => ['code' => $error->status, 'message' => $error->getMessage()]];
        }
    }
}
