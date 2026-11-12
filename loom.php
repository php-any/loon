<?php

declare(strict_types=1);

use App\Grpc\Services\ReleasePolicyService;
use App\Services\Customers\CheckoutOffer;
use Loom\Application;
use Loom\Config;
use Loom\Http\Middleware\RequestLog;
use Loom\ServiceResolver;

require_once __DIR__ . '/framework/bootstrap.php';

// 组合根，对应 ASP.NET Core 的 Program.cs。直接执行时加载 .env 并运行命令；被 require 时不读 .env，
// 返回构建好的 Application，调用方可以先定义 $configureServices(ServiceCollection) 替换服务（测试用）。
$entry = realpath($_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__);
//
// 框架默认登记（ApplicationBuilder）：
//   单例   Config、Options、Network、Process、Host、HttpServer、TcpServer、GrpcServer、JobDispatcher、TimerRunner
//   作用域 Database、RequestContext；未登记的具体类（控制器、命令、中间件等）
//   后台   TimerHostedService，只在 serve 的 Host 里运行
// 每个工作单元（HTTP 请求、TCP 消息、gRPC 调用、命令、队列/调度节拍、定时器节拍）各开一个 ServiceScope，
// 作用域里的模型静态查询使用该作用域的 Database。
$builder = Application::createBuilder(__DIR__, $entry ? (getenv('LOOM_ENV_FILE') ?: __DIR__ . '/.env') : null);

// 单例：体验套餐没有请求状态，第一次取出时按 config/checkout.php 计算，整个进程共用。
$builder->services->addSingleton(CheckoutOffer::class, static function (ServiceResolver $services): CheckoutOffer {
    $checkout = $services->get(Config::class)->get('checkout');
    if (!is_array($checkout)) {
        throw new \LogicException('checkout configuration must be an array');
    }
    return CheckoutOffer::from($checkout);
});

if (isset($configureServices)) {
    $configureServices($builder->services);
}

$builder->addModule(\App\AppModule::class);

$builder->monitoring->authorize(static function (\Loom\ServiceResolver $resolver): bool {
    try {
        $auth = $resolver->get(\Loom\Auth::class);
        $admin = $resolver->get(\App\Services\Access\Admin::class);
        $user = $auth->user();
        return \App\Services\Access\Admin::allows($admin->grants($user['id']), 'monitor.view');
    } catch (\Throwable) {
        return false;
    }
});

$app = $builder->build();

// 全局中间件，按请求作用域解析。
$app->use(RequestLog::class);

// 控制器、任务、定时器和带 Command 注解的命令类由模块注解发现，这里只写手工映射。
$app->mapGrpc('/loom.examples.ReleasePolicy/Evaluate', [ReleasePolicyService::class, 'evaluate']);

if (!$entry) {
    return $app;
}

// 命令在自己的作用域里执行；serve / tcp:serve / grpc:serve 在这里阻塞，每个请求、消息或调用再各开作用域。
try {
    $code = $app->run(array_slice($argv, 1));
} finally {
    $app->dispose();
}
exit($code);
