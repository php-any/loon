<?php

declare(strict_types=1);

chdir(__DIR__);
// 应用入口负责安装第三方依赖，先于框架与应用配置加载。
if (is_file(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}
$bootstrap = require __DIR__ . '/framework/bootstrap/autoload.php';
\Loom\Environment::load(getenv('LOOM_ENV_FILE') ?: __DIR__ . '/.env');
// PHP 在加载配置之前加载 .env。服务注册回调只在根作用域执行，子作用域共享注册规则。
$scope = $bootstrap(__DIR__, static function (\Loom\Container $services): void {
    $services->scoped(\Loom\Console\CommandRegistrar::class, \App\Console\ApplicationCommands::class);
    $services->scoped(\Loom\Http\RouteProvider::class, \App\Http\ApplicationRoutes::class);
    $services->scoped(\Loom\Grpc\RouteProvider::class, \App\Grpc\ApplicationRoutes::class);
    // 每个作用域拥有独立的请求上下文；构造函数依赖会自动注入。
    $services->scoped(\Loom\RequestContext::class);
    // 显式工厂注入：每个作用域拥有独立的数据库连接。
    $services->scoped(\Loom\Database::class, static function (\Loom\Container $services): \Loom\Database {
        return new \Loom\Database($services->get(\Loom\Config::class));
    });
});
try {
    $code = $scope->services()->get(\Loom\Cli::class)->run(array_slice($argv, 1));
} finally {
    $scope->close();
}
exit($code);
