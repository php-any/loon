<?php

declare(strict_types=1);
$services = $scope->services();
$config = $services->get(\Loom\Config::class);
$services->get(\Loom\ModuleRegistry::class)->configured()->services($services);
return static function (array $envelope) use ($services): array {
    $routes = [];
    if ($services->has(\Loom\Grpc\RouteProvider::class)) {
        $provider = $services->get(\Loom\Grpc\RouteProvider::class);
        if (!($provider instanceof \Loom\Grpc\RouteProvider)) {
            throw new \LogicException('gRPC route provider must implement RouteProvider');
        }
        $routes = $provider->routes();
    }
    return $services->get(\Loom\Grpc\Dispatcher::class)->handle($routes, $services->get(\Loom\Config::class)->root() . '/generated/grpc/routes.json', $envelope);
};
