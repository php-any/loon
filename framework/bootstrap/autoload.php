<?php

declare(strict_types=1);
require_once dirname(__DIR__) . '/bootstrap.php';
return static function (string $applicationRoot, ?callable $configureServices = null, ?string $environmentFile = null): \Loom\ServiceScope {
    if ($environmentFile !== null) {
        \Loom\Environment::load($environmentFile);
    }
    $config = new \Loom\Config($applicationRoot);
    $config->load();
    return new \Loom\ServiceScope($config, $configureServices);
};
