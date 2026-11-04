<?php

declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';

/**
 * 断言测试条件成立，失败时立即抛出异常以终止当前测试。
 *
 * @param bool $ok 待断言的条件；为假时抛出测试失败异常。
 * @param string $message 断言失败时抛出的说明。
 * @return void 无返回值；条件失败时抛出 RuntimeException。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example kernelCheck(true, '验证说明');
 */
function kernelCheck(bool $ok, string $message): void
{
    if (!$ok) {
        throw new RuntimeException($message);
    }
}

$root = sys_get_temp_dir() . '/loom-console-' . bin2hex(random_bytes(6));
mkdir($root . '/routes', 0700, true);
mkdir($root . '/config', 0700, true);
// 旧版路由文件不得被隐式执行。
file_put_contents($root . '/routes/console.php', '<?php throw new \RuntimeException("Implicit routes loading");');
/**
 * 记录应用命令注册次数，验证注册接口只执行一次且命令可被统一调用。
 *
 * 构造参数：无，本类型未声明构造参数。
 *
 * 返回值：使用 new 构造时得到 FixtureCommandRegistrar 实例；注册到容器时由容器管理实例的创建与复用。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $instance = $services->get(FixtureCommandRegistrar::class); // 在对应测试环境中解析；无效夹具用于断言异常
 */
final class FixtureCommandRegistrar implements Loom\Console\CommandRegistrar
{
    public int $registrations = 0;
    /**
     * 增加注册次数并安装固定返回 7 的 fixture:status 命令。
     *
     * @param Loom\Console\Console $console 接收命令注册或执行请求的控制台实例。
     * @return void 无返回值，修改控制台命令表。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $registrar->register($console);
     */
    public function register(Loom\Console\Console $console): void
    {
        $this->registrations++;
        $console->command('fixture:status', function ($input, $output) { return 7; });
    }
}
$scope = new Loom\ServiceScope(new Loom\Config($root));
$services = $scope->container();
$services->scoped(Loom\Console\CommandRegistrar::class, FixtureCommandRegistrar::class);
$out = '';
$err = '';
$services->instance(Loom\Console\Output::class, new Loom\Console\Output(function ($text, $stderr) use (&$out, &$err) {
    if ($stderr) {
        $err .= $text;
    } else {
        $out .= $text;
    }
}));
// 注册、帮助、参数错误和清理都不得解析数据库、服务器或 worker。
foreach ([Loom\Database::class, Loom\Server::class, Loom\TcpServer::class, Loom\GrpcServer::class, Loom\Queue::class, Loom\Schedule::class] as $service) {
    $services->scoped($service, function ($container) use ($service): object {
        throw new RuntimeException('Unexpected resolution: ' . $service);
    });
}
try {
    $kernel = $services->get(Loom\Console\ConsoleKernel::class);
    $console = $kernel->console();
    kernelCheck($console === $kernel->console(), 'Registration must be idempotent');
    kernelCheck($services->get(Loom\Console\CommandRegistrar::class)->registrations === 1, 'Registrar runs once');
    foreach (['doctor', 'serve', 'tcp:serve', 'grpc:serve', 'migrate:up', 'migrate:down', 'migrate:status', 'queue:work', 'schedule:work', '_job', 'make:command', 'fixture:status'] as $name) {
        kernelCheck($console->has($name), 'Unified registry: ' . $name);
        kernelCheck($console->call(['help', $name]) === 0, 'Programmatic built-in help: ' . $name);
        kernelCheck($console->call([$name, '--help']) === 0, 'Built-in help option: ' . $name);
    }
    kernelCheck($console->call(['fixture:status']) === 7, 'Application interface command');
    kernelCheck($console->call(['list']) === 0 && !str_contains($out, '  _job  '), 'Hidden command not listed');
    foreach (['serve', 'migrate:up', 'queue:work', 'schedule:work'] as $name) {
        kernelCheck($console->call([$name, '--unknown']) === 2, 'Input validation before dependencies');
    }
    $duplicate = false;
    try {
        $console->command('serve', function ($input, $output) {});
    } catch (LogicException $error) {
        $duplicate = true;
    }
    kernelCheck($duplicate, 'Application cannot shadow built-in command');
    $context = $services->get(Loom\RequestContext::class);
    $context->bind(new Loom\Request(['method' => 'GET', 'path' => '/']));
    kernelCheck($services->get(Loom\Cli::class)->run(['command', 'fixture:status']) === 7, 'Legacy command prefix');
    $cleared = false;
    try {
        $context->current();
    } catch (LogicException $error) {
        $cleared = true;
    }
    kernelCheck($cleared, 'Kernel clears request context');
    kernelCheck($services->resolved(Loom\Database::class) === null, 'Cleanup leaves unused database unresolved');
    $console->command('fixture:fail', function ($input, $output) {
        throw new RuntimeException('expected command failure');
    });
    $context->bind(new Loom\Request(['method' => 'GET', 'path' => '/']));
    kernelCheck($kernel->handle(['fixture:fail']) === 1 && str_contains($err, 'expected command failure'), 'Execution exception status');
    $cleared = false;
    try {
        $context->current();
    } catch (LogicException $error) {
        $cleared = true;
    }
    kernelCheck($cleared, 'Cleanup after command exception');

    // 未绑定应用注册接口时，框架内置命令仍然可用。
    unlink($root . '/routes/console.php');
    $other = new Loom\ServiceScope(new Loom\Config($root));
    try {
        kernelCheck($other->container()->get(Loom\Console\ConsoleKernel::class)->console()->has('serve'), 'Optional application registrar');
    } finally {
        $other->close();
    }
} finally {
    $scope->close();
    if (is_file($root . '/routes/console.php')) {
        unlink($root . '/routes/console.php');
    }
    rmdir($root . '/routes');
    rmdir($root . '/config');
    rmdir($root);
}
echo "loom_console_kernel_test: OK\n";
