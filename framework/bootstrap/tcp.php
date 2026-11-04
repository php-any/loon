<?php

declare(strict_types=1);
$services = $scope->container();
$config = $services->get(\Loom\Config::class);
return static function (array $message) use ($config, $scope): array {
    $scope = $scope->fork();
    $services = $scope->container();
    try {
        return \Loom\ModelContext::during($scope, static function () use ($services, $config, $message): array {
            $services->get(\Loom\ModuleRegistry::class)->configured()->services($services);
            return $services->get($config->get('tcp')['handler'])->handle($message);
        });
    } finally {
        $scope->close();
    }
};
