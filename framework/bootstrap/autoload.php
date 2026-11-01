<?php

declare(strict_types=1);
require_once dirname(__DIR__) . '/bootstrap.php';
return static function (string $applicationRoot, ?callable $configureServices = null): \Loom\ServiceScope {
    $config = new \Loom\Config($applicationRoot);
    spl_autoload_register(static function (string $class) use ($config): void {
        foreach (['App\\' => 'app/', 'Generated\\Grpc\\' => 'generated/grpc/'] as $prefix => $directory) {
            if (str_starts_with($class, $prefix)) {
                $path = $config->path($directory . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php');
                if (is_file($path)) {
                    require_once $path;
                }
                return;
            }
        }
    });
    $config->load();
    return new \Loom\ServiceScope($config, $configureServices);
};
