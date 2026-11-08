<?php

require_once dirname(__DIR__) . '/bootstrap.php';

use Loom\Console\Console;
use Loom\Console\Command;
use Loom\Console\Definition;
use Loom\Console\InputError;
use Loom\Console\Output;

/**
 * 断言测试条件成立，失败时立即抛出异常以终止当前测试。
 *
 * @param mixed $ok 待断言的条件；为假时抛出测试失败异常。
 * @param mixed $message 断言失败时抛出的说明。
 * @return void 无返回值；条件失败时抛出 RuntimeException。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example consoleCheck(true, '验证说明');
 */
function consoleCheck($ok, $message)
{
    if (!$ok) {
        throw new RuntimeException($message);
    }
}
$definition = new Definition('mail:send {user : 用户编号} {names?*} {--Q|queue=sync : 队列} {--force} {--tag=*}', '示例');
$input = $definition->parse(['7', 'Ada', 'Lin', '-Q', 'later', '--force', '--tag=one', '--tag', 'two']);
consoleCheck($input->argument('user') === '7' && $input->argument('names') === ['Ada', 'Lin'], '数组参数');
consoleCheck($input->option('queue') === 'later' && $input->option('force') === true && $input->option('tag') === ['one', 'two'], '选项解析');
$input = $definition->parse(['-1']);
consoleCheck($input->argument('user') === '-1' && $input->option('queue') === 'sync' && $input->option('tag') === [], '默认值');
$input = $definition->parse(['7', '--', '--force', '-Q']);
consoleCheck($input->argument('names') === ['--force', '-Q'] && !$input->option('force'), '选项终止符');
consoleCheck($definition->parse(['7', '-Qfast'])->option('queue') === 'fast', '短选项紧邻值');
consoleCheck($definition->parse(['7', '--queue='])->option('queue') === '', '空选项值');
$url = new Definition('fetch {url=https://example.test : URL} {--count=2}', '');
consoleCheck($url->parse([])->argument('url') === 'https://example.test', '默认值中的冒号');
foreach ([[], ['7', '--unknown'], ['7', '--queue'], ['7', '--queue', '--force'], ['7', '--force=yes']] as $tokens) {
    $caught = false;
    try {
        $definition->parse($tokens);
    } catch (InputError $error) {
        $caught = true;
    } consoleCheck($caught, '拒绝非法输入');
}
foreach (['bad {a?} {b}', 'bad {ids*} {other?}', 'bad {--h|hello}', 'bad {--q|queue} {--q|quiet}', 'bad {x} {x}', 'bad junk', 'bad {--bad?}'] as $signature) {
    $caught = false;
    try {
        new Definition($signature, '');
    } catch (LogicException $error) {
        $caught = true;
    } consoleCheck($caught, '拒绝非法签名：' . $signature);
}
$out = '';
$err = '';
$called = 0;
$console = new Console(new Output(function ($text, $stderr) use (&$out, &$err) {
    if ($stderr) {
        $err .= $text;
    } else {
        $out .= $text;
    }
}));
$console->command('hello {name=世界} {--loud}', function ($input, $output) use (&$called) {
    $called++;
    $output->line('你好，' . $input->argument('name'));
    return 0;
}, '问候');
$console->command('required {user}', function ($input, $output) use (&$called) {
    $called++;
    return 0;
});
consoleCheck($console->run(['required', '--help']) === 0 && $called === 0 && strpos($out, '用法：required') !== false, '帮助不执行业务，也不要求必填参数');
consoleCheck($console->run(['hello']) === 0 && $called === 1 && strpos($out, '你好，世界') !== false, '闭包命令');
consoleCheck($console->run(['hello', 'A', 'B']) === 2 && $called === 1, '多余参数阻止命令执行');
consoleCheck($console->run(['missing']) === 2 && strpos($err, '未知命令') !== false, '未知命令退出码');
$console->command('fail', function ($i, $o) {
    throw new RuntimeException('可见的失败信息');
});
consoleCheck($console->run(['fail']) === 1 && strpos($err, '可见的失败信息') !== false, '异常退出码');
$console->command('status', function ($i, $o) {
    return 7;
});
$console->command('nested', function ($i, $o) use ($console) {
    return $console->call(['status']);
});
consoleCheck($console->run(['nested']) === 7, '嵌套调用退出码');
$console->command('loop', function ($i, $o) use ($console) {
    return $console->call(['loop']);
});
consoleCheck($console->run(['loop']) === 1, '嵌套调用次数限制');
/**
 * 提供固定问候文本，验证控制台构造函数依赖注入。
 *
 * 构造参数：无，本类型未声明构造参数。
 *
 * 返回值：使用 new 构造时得到 ConsoleGreeter 实例；注册到容器时由容器管理实例的创建与复用。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $instance = $services->get(ConsoleGreeter::class); // 在对应测试环境中解析；无效夹具用于断言异常
 */
class ConsoleGreeter
{
    /**
     * 提供用于验证注入成功的固定文本。
     *
     * 参数：无。
     *
     * @return string 字符串 injected。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $text = (new ConsoleGreeter())->text();
     */
    public function text(): string
    {
        return 'injected';
    }
}
/**
 * 验证命令参数、注入服务、输出通道和自定义退出码。
 *
 * 构造参数：
 * - $greeter（ConsoleGreeter）：用于输出固定问候文本的测试服务。
 *
 * 返回值：使用 new 构造时得到 TestConsoleCommand 实例；注册到容器时由容器管理实例的创建与复用。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $instance = $services->get(TestConsoleCommand::class); // 在对应测试环境中解析；无效夹具用于断言异常
 */
class TestConsoleCommand extends Command
{
    protected string $signature = 'class:test {--name=Ada}';
    private ConsoleGreeter $greeter;
    /**
     * 初始化 TestConsoleCommand 实例及其依赖。验证命令参数、注入服务、输出通道和自定义退出码。
     *
     * @param ConsoleGreeter $greeter 用于输出固定问候文本的测试服务。
     * @return void 无直接返回值；通过 new 表达式取得初始化后的实例。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $instance = $services->get(TestConsoleCommand::class); // 在对应测试环境中解析；无效夹具用于断言异常
     */
    public function __construct(ConsoleGreeter $greeter)
    {
        $this->greeter = $greeter;
    }
    /**
     * 输出注入服务文本与 name 选项，并写入错误通道。
     *
     * 参数：无。
     *
     * @return int 固定退出码 3，验证退出码透传。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $code = $console->run(['class:test', '--name=示例']);
     */
    public function handle(): int
    {
        $this->info($this->greeter->text() . ' ' . $this->option('name'));
        $this->error('标准错误流正常');
        return 3;
    }
}
$constructed = 0;
$services = new Loom\ServiceCollection();
$services->addScoped(TestConsoleCommand::class, function (Loom\ServiceResolver $services) use (&$constructed) {
    $constructed++;
    return new TestConsoleCommand($services->get(ConsoleGreeter::class));
});
$scope = $services->build()->createScope();
$console->add(TestConsoleCommand::class);
consoleCheck($constructed === 0, '注册命令不解析工厂');
consoleCheck($console->run(['help', 'class:test'], $scope) === 0 && $constructed === 0, '帮助不解析工厂');
consoleCheck($console->run(['list'], $scope) === 0 && $constructed === 0, '列表不解析工厂');
consoleCheck($console->run(['class:test', '--unknown'], $scope) === 2 && $constructed === 0, '输入校验先于工厂');
consoleCheck($console->run(['class:test', '--name=Lin'], $scope) === 3 && strpos($out, 'injected Lin') !== false && strpos($err, '标准错误流正常') !== false, '命令类工厂与输出流');
consoleCheck($constructed === 1, '执行时才解析命令工厂');
consoleCheck($console->run(['list'], $scope) === 0 && strpos($out, 'class:test') !== false, 'list');
$console->command('scoped', function (Loom\Console\Input $input, Loom\Console\Output $output, Loom\ServiceScope $current, ConsoleGreeter $greeter) use ($scope) {
    return $current === $scope && $greeter === $scope->get(ConsoleGreeter::class) ? 5 : 6;
});
consoleCheck($console->run(['scoped'], $scope) === 5, '闭包命令从给定作用域解析参数');
$scope->dispose();

// 应用命令表：框架内置命令与应用命令统一登记；帮助、参数错误都不得解析服务器或 worker。
// 作用域的 run() 会为模型绑定 Database，所以这里不替换 Database；它在构造时不打开连接。
$root = sys_get_temp_dir() . '/loom-console-' . bin2hex(random_bytes(6));
mkdir($root . '/routes', 0700, true);
mkdir($root . '/config', 0700, true);
// 旧版路由文件不得被隐式执行。
file_put_contents($root . '/routes/console.php', '<?php throw new \RuntimeException("Implicit routes loading");');
file_put_contents($root . '/config/modules.php', '<?php return [];');
$app = null;
try {
    $builder = Loom\Application::createBuilder($root);
    foreach ([Loom\HttpServer::class, Loom\TcpServer::class, Loom\GrpcServer::class] as $service) {
        $builder->services->addSingleton($service, static function () use ($service): object {
            throw new RuntimeException('Unexpected resolution: ' . $service);
        });
    }
    foreach ([Loom\Queue::class, Loom\Schedule::class] as $service) {
        $builder->services->addScoped($service, static function () use ($service): object {
            throw new RuntimeException('Unexpected resolution: ' . $service);
        });
    }
    $app = $builder->build();
    $app->mapCommand('fixture:status', function ($input, $output) {
        return 7;
    });
    $registry = $app->services->get(Console::class);
    foreach (['doctor', 'serve', 'tcp:serve', 'grpc:serve', 'migrate:up', 'migrate:down', 'migrate:status', 'queue:work', 'schedule:work', '_job', 'make:command', 'fixture:status'] as $name) {
        consoleCheck($registry->has($name), 'Unified registry: ' . $name);
        ob_start();
        try {
            consoleCheck($app->run(['help', $name]) === 0, 'Programmatic built-in help: ' . $name);
            consoleCheck($app->run([$name, '--help']) === 0, 'Built-in help option: ' . $name);
        } finally {
            ob_end_clean();
        }
    }
    consoleCheck($app->run(['fixture:status']) === 7, 'Application interface command');
    ob_start();
    try {
        consoleCheck($app->run(['list']) === 0, 'Application list');
    } finally {
        $listed = ob_get_clean();
    }
    consoleCheck(str_contains($listed, 'fixture:status') && !str_contains($listed, '  _job  '), 'Hidden command not listed');
    $duplicate = false;
    try {
        $app->mapCommand('serve', function ($input, $output) {});
    } catch (LogicException $error) {
        $duplicate = true;
    }
    consoleCheck($duplicate, 'Application cannot shadow built-in command');
    // 应用的命令表直接写标准流；错误路径用捕获输出的命令表在应用作用域里验证。
    $out = '';
    $err = '';
    $captured = new Console(new Output(function ($text, $stderr) use (&$out, &$err) {
        if ($stderr) {
            $err .= $text;
        } else {
            $out .= $text;
        }
    }));
    foreach ([Loom\Console\Commands\ServeCommand::class, Loom\Console\Commands\MigrateUpCommand::class, Loom\Console\Commands\QueueWorkCommand::class, Loom\Console\Commands\ScheduleWorkCommand::class] as $class) {
        $captured->add($class);
    }
    $captured->command('fixture:fail', function ($input, $output) {
        throw new RuntimeException('expected command failure');
    });
    $codes = $app->services->withScope(static function (Loom\ServiceScope $scope) use ($captured): array {
        $codes = [];
        foreach (['serve', 'migrate:up', 'queue:work', 'schedule:work'] as $name) {
            $codes[] = $captured->run([$name, '--unknown'], $scope);
        }
        $codes[] = $captured->run(['serve'], $scope);
        $codes[] = $captured->run(['fixture:fail'], $scope);
        return $codes;
    });
    consoleCheck(array_slice($codes, 0, 4) === [2, 2, 2, 2], 'Input validation before dependencies');
    consoleCheck($codes[4] === 1 && str_contains($err, 'Unexpected resolution'), 'Command dependencies resolve from the application scope at execution');
    consoleCheck($codes[5] === 1 && str_contains($err, 'expected command failure'), 'Execution exception status');
} finally {
    if ($app !== null) {
        $app->dispose();
    }
    unlink($root . '/routes/console.php');
    unlink($root . '/config/modules.php');
    rmdir($root . '/routes');
    rmdir($root . '/config');
    rmdir($root);
}
echo "loom_console_test: OK\n";
