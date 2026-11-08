<?php

declare(strict_types=1);

use App\Grpc\Services\ReleasePolicyService;
use App\Models\Projects\Project;
use App\Models\Projects\Task;
use App\Services\Customers\CheckoutOffer;
use Loom\Application;
use Loom\Config;
use Loom\Console\Input;
use Loom\Console\InputError;
use Loom\Console\Output;
use Loom\Http\Middleware\RequestLog;
use Loom\Models\User;
use Loom\Queue;
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

$app = $builder->build();

// 全局中间件，按请求作用域解析。
$app->use(RequestLog::class);

// 控制器、任务、定时器和带 ConsoleCommand 的命令类由模块注解发现，这里只写手工映射。
$app->mapGrpc('/loom.examples.ReleasePolicy/Evaluate', [ReleasePolicyService::class, 'evaluate']);

$consoleUser = static function (string $value): int {
    if (!ctype_digit($value) || (int) $value < 1) {
        throw new InputError('user 必须是正整数');
    }
    $id = (int) $value;
    if (User::find($id) === null) {
        throw new InputError('用户不存在：' . $value);
    }
    return $id;
};

$app->mapCommand('projects:tasks {user : 用户 ID} {--completed : 仅显示已完成任务} {--P|project= : 按项目 ID 筛选} {--json : 输出 JSON}', static function (Input $input, Output $output) use ($consoleUser): int {
    $id = $consoleUser($input->argument('user'));
    $query = Task::whereUserId($id);
    if ($input->option('completed')) {
        $query = $query->where('completed', '=', 1);
    }
    if ($input->option('project') !== null) {
        $project = $input->option('project');
        if (!ctype_digit($project) || (int) $project < 1) {
            throw new InputError('project 必须是正整数');
        }
        Project::whereUserId($id)->whereId((int) $project)->firstOrFail();
        $query = $query->where('project_id', '=', (int) $project);
    }
    $rows = $query->orderBy('id', 'DESC')->rows();
    if ($input->option('json')) {
        $output->json($rows);
        return 0;
    }
    $table = [];
    foreach ($rows as $task) {
        $table[] = [$task['id'], $task['completed'] ? '已完成' : '待完成', $task['title']];
    }
    $output->table(['编号', '状态', '任务'], $table);
    $output->line(count($rows) . ' 个任务');
    return 0;
}, '查询用户的项目任务');

$app->mapCommand('reports:dispatch {user : 用户 ID} {--delay=0 : 延迟秒数，0–86400} {--dry-run : 只检查，不入队}', static function (Input $input, Output $output, Queue $queue) use ($consoleUser): int {
    $id = $consoleUser($input->argument('user'));
    $delay = $input->option('delay');
    if (!ctype_digit($delay) || (int) $delay > 86400) {
        throw new InputError('delay 必须是 0–86400 的整数');
    }
    if ($input->option('dry-run')) {
        $output->info('检查通过：将为用户 ' . $id . ' 生成摘要，延迟 ' . $delay . ' 秒。');
        return 0;
    }
    $job = $queue->dispatch('studio.digest', [], $id, (int) $delay);
    $output->info('已入队 Job #' . $job . '；由运行中的 队列消费者 执行。');
    return 0;
}, '把进展摘要交给持久化队列');

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
