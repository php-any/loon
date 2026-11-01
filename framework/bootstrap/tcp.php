<?php

declare(strict_types=1);
$services = $scope->services();
$config = $services->get(\Loom\Config::class);
return static function (array $message) use ($config, $scope): array {
    $scope = $scope->fork();
    $services = $scope->services();
    try {
        $services->get(\Loom\ModuleRegistry::class)->configured()->services($services);
        return $services->get($config->get('tcp')['handler'])->handle($message);
    } finally {
        $scope->close();
    }
};
