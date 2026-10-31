<?php
require __DIR__ . '/../../runtime/framework/php/loom.php';
require __DIR__ . '/../../runtime/framework/php/console.php';

use Loom\Console\Console;
use Loom\Console\Command;
use Loom\Console\Definition;
use Loom\Console\InputError;
use Loom\Console\Output;

function consoleCheck($ok, $message) { if (!$ok) { throw new RuntimeException($message); } }
$definition = new Definition('mail:send {user : 用户编号} {names?*} {--Q|queue=sync : 队列} {--force} {--tag=*}', '示例');
$input = $definition->parse(['7', 'Ada', 'Lin', '-Q', 'later', '--force', '--tag=one', '--tag', 'two']);
consoleCheck($input->argument('user') === '7' && $input->argument('names') === ['Ada', 'Lin'], '数组参数');
consoleCheck($input->option('queue') === 'later' && $input->option('force') === true && $input->option('tag') === ['one','two'], '选项解析');
$input = $definition->parse(['-1']);
consoleCheck($input->argument('user') === '-1' && $input->option('queue') === 'sync' && $input->option('tag') === [], '默认值');
$input = $definition->parse(['7', '--', '--force', '-Q']);
consoleCheck($input->argument('names') === ['--force','-Q'] && !$input->option('force'), '选项终止符');
consoleCheck($definition->parse(['7','-Qfast'])->option('queue') === 'fast', '短选项紧邻值');
consoleCheck($definition->parse(['7','--queue='])->option('queue') === '', '空选项值');
$url = new Definition('fetch {url=https://example.test : URL} {--count=2}', '');
consoleCheck($url->parse([])->argument('url') === 'https://example.test', '默认值中的冒号');
foreach ([[], ['7','--unknown'], ['7','--queue'], ['7','--queue','--force'], ['7','--force=yes']] as $tokens) {
    $caught = false; try { $definition->parse($tokens); } catch (InputError $error) { $caught = true; } consoleCheck($caught, '拒绝非法输入');
}
foreach (['bad {a?} {b}', 'bad {ids*} {other?}', 'bad {--h|hello}', 'bad {--q|queue} {--q|quiet}', 'bad {x} {x}', 'bad junk', 'bad {--bad?}'] as $signature) {
    $caught = false; try { new Definition($signature, ''); } catch (LogicException $error) { $caught = true; } consoleCheck($caught, '拒绝非法签名：' . $signature);
}
$out = ''; $err = ''; $called = 0;
$console = new Console(new Output(function ($text, $stderr) use (&$out, &$err) { if ($stderr) { $err .= $text; } else { $out .= $text; } }));
$console->command('hello {name=世界} {--loud}', function ($input, $output) use (&$called) { $called++; $output->line('你好，' . $input->argument('name')); return 0; }, '问候');
$console->command('required {user}', function ($input, $output) use (&$called) { $called++; return 0; });
consoleCheck($console->run(['required','--help']) === 0 && $called === 0 && strpos($out, '用法：required') !== false, '帮助不执行业务，也不要求必填参数');
consoleCheck($console->run(['hello']) === 0 && $called === 1 && strpos($out, '你好，世界') !== false, '闭包命令');
consoleCheck($console->run(['hello','A','B']) === 2 && $called === 1, '多余参数阻止命令执行');
consoleCheck($console->run(['missing']) === 2 && strpos($err,'未知命令') !== false, '未知命令退出码');
$console->command('fail', function ($i, $o) { throw new RuntimeException('可见的失败信息'); });
consoleCheck($console->run(['fail']) === 1 && strpos($err,'可见的失败信息') !== false, '异常退出码');
$console->command('status', function ($i, $o) { return 7; });
$console->command('nested', function ($i, $o) use ($console) { return $console->call(['status']); });
consoleCheck($console->run(['nested']) === 7, '嵌套调用退出码');
$console->command('loop', function ($i,$o) use ($console) { return $console->call(['loop']); });
consoleCheck($console->run(['loop']) === 1, '嵌套调用次数限制');
class ConsoleGreeter { public function text(): string { return 'injected'; } }
class TestConsoleCommand extends Command {
    protected string $signature = 'class:test {--name=Ada}';
    private ConsoleGreeter $greeter;
    public function __construct(ConsoleGreeter $greeter) { $this->greeter = $greeter; }
    public function handle(): int { $this->info($this->greeter->text() . ' ' . $this->option('name')); $this->error('标准错误流正常'); return 3; }
}
$console->services()->bind(TestConsoleCommand::class, function ($container) { return new TestConsoleCommand($container->get(ConsoleGreeter::class)); });
$console->add(TestConsoleCommand::class);
consoleCheck($console->run(['class:test','--name=Lin']) === 3 && strpos($out,'injected Lin') !== false && strpos($err,'标准错误流正常') !== false, '命令类工厂与输出流');
consoleCheck($console->run(['list']) === 0 && strpos($out,'class:test') !== false, 'list');
echo "loom_console_test: OK\n";
