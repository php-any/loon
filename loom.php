<?php

declare(strict_types=1);

$bootstrap = require __DIR__ . '/framework/bootstrap/autoload.php';
// 回调只在根作用域执行。子作用域共享注册规则，scoped 服务各自创建。
// 环境文件传给引导函数，由它在加载 config 之前读入。
$scope = $bootstrap(__DIR__, static function (\Loom\Container $services): void {
    $services->scoped(\Loom\Console\CommandRegistrar::class, \App\Console\ApplicationCommands::class);
    $services->scoped(\Loom\Http\RouteProvider::class, \App\Http\ApplicationRoutes::class);
    $services->scoped(\Loom\Grpc\RouteProvider::class, \App\Grpc\ApplicationRoutes::class);
    // 具体类默认也是 scoped。提前注册，避免之后被改成跨请求共享。
    $services->scoped(\Loom\RequestContext::class);
    // 容器按构造函数注入 Config。这个服务只保存配置，不占有连接。
    $services->scoped(\Loom\Database::class);
}, getenv('LOOM_ENV_FILE') ?: __DIR__ . '/.env');
try {
    $code = $scope->services()->get(\Loom\Cli::class)->run(array_slice($argv, 1));
} finally {
    $scope->close();
}
exit($code);
