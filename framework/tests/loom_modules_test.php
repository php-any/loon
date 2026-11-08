<?php

declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';
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
final class ModuleRequestLogs
{
    public array $records = [];
}
$root = dirname(__DIR__, 2);
$config = new Loom\Config($root);
$discovery = new Loom\ModuleDiscovery($config);
foreach ([ModuleFixtures\Value::class, ModuleFixtures\Endpoint::class, ModuleFixtures\Work::class, ModuleFixtures\TimerTask::class] as $class) {
    $discovery->register($class);
    $discovery->register($class);
}
checkModule($discovery->services() === ['module.value' => ModuleFixtures\Value::class] && $discovery->serviceLifetime('module.value') === 'scoped', 'Service declarations keep id and lifetime');
checkModule($discovery->timers() === [ModuleFixtures\TimerTask::class] && array_keys($discovery->jobs()) === ['module.job'], 'Timer and job declarations are discovered once');
// 按 ApplicationBuilder 的方式把发现结果装进容器、路由和任务分发。
$services = new Loom\ServiceCollection();
foreach ($discovery->services() as $id => $class) {
    $services->tryAdd(new Loom\ServiceDescriptor($id, Loom\ServiceLifetime::from($discovery->serviceLifetime($id)), $class));
}
$services->addSingleton(ModuleFixtures\TimerTask::class, static fn (): ModuleFixtures\TimerTask => new ModuleFixtures\TimerTask(new ModuleFixtures\Value()));
$provider = $services->build();
$router = $discovery->router();
$kernel = new Loom\HttpKernel($router);
$result = $provider->withScope(static fn (Loom\ServiceScope $scope): Loom\Response => $kernel->handle(new Loom\Request(['method' => 'GET', 'path' => '/module/value']), $scope))->toArray();
checkModule($result['body'] === 'injected12', 'Method routes, constructor injection and middleware order');
checkModule($router->url('module.value') === '/module/value', 'Route name and controller prefix');
$values = $provider->withScope(static fn (Loom\ServiceScope $scope): array => [$scope->get('module.value'), $scope->get('module.value')]);
$other = $provider->withScope(static fn (Loom\ServiceScope $scope): object => $scope->get('module.value'));
checkModule($values[0] instanceof ModuleFixtures\Value && $values[0] === $values[1], 'Named service binds its implementation');
checkModule($values[0] !== $other, 'Scopes must isolate service instances');
$jobs = new Loom\JobDispatcher($discovery);
checkModule($jobs->kinds() === ['module.job'], 'Job kinds');
checkModule($provider->withScope(static fn (Loom\ServiceScope $scope): Loom\Response => $jobs->dispatch('module.job', new Loom\Request([]), $scope))->toArray()['body'] === 'injected', 'Queue handler injection');
$timers = new Loom\TimerRunner($provider);
foreach ($discovery->timers() as $class) {
    $timers->register($class);
}
checkModule($timers->timers() === [ModuleFixtures\TimerTask::class => 1], 'Module timer registration');
$timers->tick(ModuleFixtures\TimerTask::class);
$timers->tick(ModuleFixtures\TimerTask::class);
checkModule($provider->get(ModuleFixtures\TimerTask::class)->calls === 2, 'Module timer ticks resolve the registered service');
foreach ([ModuleFixtures\DuplicateService::class, ModuleFixtures\DuplicateJob::class, ModuleFixtures\InvalidController::class, ModuleFixtures\InvalidModel::class] as $class) {
    $rejected = false;
    try {
        $discovery->register($class);
    } catch (LogicException $error) {
        $rejected = true;
    }
    checkModule($rejected, 'Invalid declaration must fail: ' . $class);
}
$rejected = false;
try {
    $provider->withScope(static fn (Loom\ServiceScope $scope): Loom\Response => $jobs->dispatch('unknown', new Loom\Request([]), $scope));
} catch (LogicException $error) {
    $rejected = true;
}
checkModule($rejected, 'Unknown job kind must fail');
$rejected = false;
try {
    (new Loom\ModuleDiscovery($config))->add(ModuleFixtures\CycleA::class);
} catch (LogicException $error) {
    $rejected = true;
}
checkModule($rejected, 'Module cycles must fail');
$configured = Loom\ModuleDiscovery::configured($config);
$rejected = false;
try {
    $configured->register(ModuleFixtures\Value::class);
} catch (LogicException $error) {
    $rejected = str_contains($error->getMessage(), 'immutable');
}
checkModule($rejected, 'Configured metadata is frozen');
$table = Loom\ModuleDiscovery::attribute(new ReflectionClass(App\Models\Projects\Task::class), Database\Annotation\Table::class);
checkModule($table->name === 'studio_tasks', 'Model uses native std Table annotation metadata');
$logs = new ModuleRequestLogs();
$configureServices = static function (Loom\ServiceCollection $services) use ($logs): void {
    $services->addScoped(Loom\Http\Middleware\RequestLog::class, static function () use ($logs): Loom\Http\Middleware\RequestLog {
        return new Loom\Http\Middleware\RequestLog(static function (string $line) use ($logs): void {
            $logs->records[] = json_decode($line, true);
        });
    });
};
$app = require $root . '/loom.php';
try {
    $page = $app->handle(new Loom\Request(['method' => 'GET', 'path' => '/demo']))->toArray();
    checkModule($page['status'] === 200 && str_contains($page['body'], 'release-app'), 'Application module discovers release scenario routes');
    checkModule($app->handle(new Loom\Request(['method' => 'GET', 'path' => '/hello/Ada']))->toArray()['status'] === 404, 'Generic greeting demo is not an application endpoint');
    checkModule($app->handle(new Loom\Request(['method' => 'GET', 'path' => '/api/studio/state']))->toArray()['status'] === 401, 'Protected route keeps authentication');
} finally {
    $app->dispose();
}
checkModule(count($logs->records) === 3 && $logs->records[2]['status'] === 401, 'Application routes retain request logging');
echo "loom_modules_test: OK\n";
