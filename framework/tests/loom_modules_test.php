<?php

declare(strict_types=1);
$runtimeScope = (require dirname(__DIR__) . '/bootstrap/autoload.php')(dirname(__DIR__, 2));
$runtimeServices = $runtimeScope->container();
require __DIR__ . '/fixtures/modules/components.php';
/**
 * 断言测试条件成立，失败时立即抛出异常以终止当前测试。
 *
 * @param bool $condition 待断言的条件；为假时抛出测试失败异常。
 * @param string $message 断言失败时抛出的说明。
 * @return void 无返回值；条件失败时抛出 RuntimeException。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example checkModule(true, '验证说明');
 */
function checkModule(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
$registry = new \Loom\ModuleRegistry(new \Loom\ModuleDiscovery($runtimeServices->get(\Loom\Config::class)), $runtimeServices->get(\Loom\Config::class), $runtimeScope);
foreach ([\ModuleFixtures\Value::class, \ModuleFixtures\Endpoint::class, \ModuleFixtures\Work::class, \ModuleFixtures\TimerTask::class] as $class) {
    $registry->register($class);
    $registry->register($class);
}
$app = new \Loom\Application();
$registry->services($app->container());
$registry->routes($app);
$result = $app->handle(new \Loom\Request(['method' => 'GET', 'path' => '/module/value']))->toArray();
checkModule($result['body'] === 'injected12', 'Method routes, constructor injection and middleware order');
checkModule($app->url('module.value') === '/module/value', 'Route name and controller prefix');
$first = $app->container()->get('module.value');
checkModule($first instanceof \ModuleFixtures\Value, 'Named service binds its implementation');
checkModule($first !== $registry->container()->get('module.value'), 'Containers must isolate service instances');
checkModule($registry->job('module.job', new \Loom\Request([]))->toArray()['body'] === 'injected', 'Queue handler injection');
$timers = $registry->timers($app->container());
$timer = $timers->register(\ModuleFixtures\TimerTask::class);
checkModule($timer !== $app->container()->get(\ModuleFixtures\TimerTask::class), 'Timers must own a separate service scope');
$timers->start();
usleep(1200000);
$timers->stop();
checkModule($timer->calls === 1, 'Module timer discovery and service injection');
foreach ([\ModuleFixtures\DuplicateService::class, \ModuleFixtures\DuplicateJob::class, \ModuleFixtures\InvalidController::class, \ModuleFixtures\InvalidModel::class] as $class) {
    $rejected = false;
    try {
        $registry->register($class);
    } catch (LogicException $error) {
        $rejected = true;
    }
    checkModule($rejected, 'Invalid declaration must fail: ' . $class);
}
$rejected = false;
try {
    $registry->job('unknown', new \Loom\Request([]));
} catch (LogicException $error) {
    $rejected = true;
}
checkModule($rejected, 'Unknown job kind must fail');
$rejected = false;
try {
    (new \Loom\ModuleRegistry(new \Loom\ModuleDiscovery($runtimeServices->get(\Loom\Config::class)), $runtimeServices->get(\Loom\Config::class), $runtimeScope))->add(\ModuleFixtures\CycleA::class);
} catch (LogicException $error) {
    $rejected = true;
}
checkModule($rejected, 'Module cycles must fail');
$configured = $runtimeServices->get(\Loom\ModuleRegistry::class)->configured();
$table = \Loom\ModuleRegistry::attribute(new \ReflectionClass(\App\Models\Projects\Task::class), \Database\Annotation\Table::class);
checkModule($table->name === 'studio_tasks', 'Model uses native std Table annotation metadata');
$configured->add(\App\AppModule::class);
$requestLogs = [];
$runtimeServices->scoped(\Loom\Http\Middleware\RequestLog::class, static function ($services) use (&$requestLogs): object {
    return new \Loom\Http\Middleware\RequestLog(static function (string $line) use (&$requestLogs): void {
        $requestLogs[] = json_decode($line, true);
    });
});
$http = $runtimeScope->load('framework/bootstrap/http.php');
$page = $http->handle(new \Loom\Request(['method' => 'GET', 'path' => '/demo']))->toArray();
checkModule($page['status'] === 200 && str_contains($page['body'], 'release-app'), 'Application module discovers release scenario routes');
checkModule($http->handle(new \Loom\Request(['method' => 'GET', 'path' => '/hello/Ada']))->toArray()['status'] === 404, 'Generic greeting demo is not an application endpoint');
$runtimeServices->get(\Loom\Database::class)->connect((string)getenv('LOOM_DATABASE'));
$runtimeServices->get(\Loom\Migrations::class)->initialize();
$request = new \Loom\Request(['method' => 'GET', 'path' => '/api/studio/state']);
$runtimeServices->get(\Loom\RequestContext::class)->bind($request);
try {
    checkModule($http->handle($request)->toArray()['status'] === 401, 'Protected route keeps authentication');
} finally {
    $runtimeServices->get(\Loom\RequestContext::class)->bind(null);
    $runtimeServices->get(\Loom\Database::class)->close();
}
checkModule(count($requestLogs) === 3 && $requestLogs[2]['status'] === 401, 'Cached application routes retain request logging');
echo "loom_modules_test: OK\n";
