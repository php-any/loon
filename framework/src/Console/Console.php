<?php

declare(strict_types=1);

namespace Loom\Console;

use Loom\Invoker;
use Loom\ServiceProvider;
use Loom\ServiceResolver;

/**
 * 命令表。登记闭包或 Command 子类，解析参数后在给定的服务作用域里执行，并把输入错误与其它异常收成稳定退出码。
 * list 和 help 是保留命令；嵌套深度达到 16 时不再执行。
 *
 * 闭包处理器按 Invoker 绑定参数：Input、Output 来自这次调用，其余类类型参数从作用域解析。Command 子类从作用域解析，构造函数可注入服务。
 *
 * @example $console->command('ping', static fn (Output $out): int => 0, '连通性检查'); $code = $console->run(['ping'], $scope);
 */
final class Console
{
    private array $commands = [];
    private Output $output;
    private int $depth = 0;
    private ?ServiceResolver $services = null;

    /**
     * @param ?Output $output 帮助、列表和错误要写入的对象；null 表示新建默认 Output。
     */
    public function __construct(?Output $output = null)
    {
        $this->output = $output ?? new Output();
    }

    public function output(): Output
    {
        return $this->output;
    }

    /**
     * 名称是保留命令 list、help，或已经登记时为 true。隐藏命令也算存在。
     */
    public function has(string $name): bool
    {
        return in_array($name, ['list', 'help'], true) || isset($this->commands[$name]);
    }

    /**
     * 解析签名并登记闭包。名称是 list、help 或已被占用时抛出 LogicException；签名不合法时由 Definition 抛出 LogicException。
     *
     * @param string $signature 命令名加上参数、选项字段，例如 greet {name}。
     * @param callable $handler 返回 0–255 的整数或 null。参数按 Invoker 绑定。
     * @param string $description 列表和帮助里的说明。
     *
     * @example $console->command('greet {name}', static function (Input $in, Output $out): int { $out->line($in->argument('name')); return 0; }, '问候用户');
     */
    public function command(string $signature, callable $handler, string $description = '', bool $hidden = false): self
    {
        $definition = new Definition($signature, $description);
        if ($this->has($definition->name)) {
            throw new \LogicException('命令名重复或属于保留名称：' . $definition->name);
        }
        $this->commands[$definition->name] = ['definition' => $definition, 'handler' => $handler, 'hidden' => $hidden];
        return $this;
    }

    /**
     * 登记 Command 子类。只读取声明的签名、描述和隐藏标记，执行时才从作用域解析实例，所以看帮助不会构造业务依赖。
     * 类不能实例化或不是 Command 子类时抛出 LogicException。
     *
     * @example $console->add(\Loom\Console\Commands\DoctorCommand::class);
     */
    public function add(string $class): self
    {
        $reflection = new \ReflectionClass($class);
        if (!$reflection->isInstantiable() || !$reflection->isSubclassOf(Command::class)) {
            throw new \LogicException('命令类必须继承 Command');
        }
        // Origami 没有 getDefaultProperties；不调用构造函数取得声明的元数据。
        $metadata = $reflection->newInstanceWithoutConstructor();
        return $this->command($metadata->signature(), static function (Input $input, Output $output, ServiceResolver $services) use ($class): ?int {
            $command = $services->get($class);
            if (!($command instanceof Command)) {
                throw new \LogicException('命令工厂必须返回 Command');
            }
            return $command->execute($input, $output);
        }, $metadata->description(), $metadata->hidden());
    }

    /**
     * 执行 list、help 或已登记命令。$argv 为 null 时读 $GLOBALS['argv'] 并去掉程序名。
     * list 带参数、help 带超过一个命令名、未知命令都返回 2。单独的 -- 之前出现 --help 或 -h 时只打印帮助。无参数的 help 与 list 一样列出命令。
     * 处理器返回 null 视为 0；不是 0–255 的整数时返回 1。InputError 返回 2，其它异常返回 1。
     *
     * @param ?array $argv 第一项是命令名。
     * @param ?ServiceResolver $services 这次执行的作用域。null 时沿用外层 run 的作用域；最外层也没有时用一个空作用域。
     * @return int 退出码。
     */
    public function run(?array $argv = null, ?ServiceResolver $services = null): int
    {
        if ($argv === null) {
            $argv = array_slice($GLOBALS['argv'], 1);
        }
        if ($this->depth >= 16) {
            $this->output->error('命令嵌套调用次数超过上限');
            return 1;
        }
        $previous = $this->services;
        $this->services = $services ?? $previous ?? (new ServiceProvider([]))->createScope();
        $this->depth++;
        try {
            return $this->dispatch($argv);
        } catch (InputError $error) {
            $this->output->error($error->getMessage());
            return 2;
        } catch (\Throwable $error) {
            $this->output->error($error->getMessage());
            return 1;
        } finally {
            $this->depth--;
            $this->services = $previous;
        }
    }

    /**
     * 在当前作用域里嵌套执行另一个命令，退出码规则同 run。
     *
     * @example $code = $console->call(['migrate:status']);
     */
    public function call(array $argv): int
    {
        return $this->run($argv);
    }

    private function dispatch(array $argv): int
    {
        $name = $argv[0] ?? 'list';
        if ($name === '--help' || $name === '-h') {
            $name = 'list';
        }
        if ($name === 'list') {
            if (count($argv) > 1) {
                throw new InputError('list 命令不接受参数');
            }
            $this->listCatalog();
            return 0;
        }
        $help = $name === 'help';
        if ($help) {
            if (count($argv) === 1) {
                $this->listCatalog();
                return 0;
            }
            if (count($argv) !== 2) {
                throw new InputError('help 命令最多接受一个命令名');
            }
            $name = $argv[1];
        }
        if (!isset($this->commands[$name])) {
            throw new InputError('未知命令：' . $name . '。请执行 list 查看可用命令。');
        }
        $command = $this->commands[$name];
        $tokens = array_slice($argv, 1);
        foreach ($tokens as $token) {
            if ($token === '--') {
                break;
            }
            if ($token === '--help' || $token === '-h') {
                $help = true;
            }
        }
        if ($help) {
            $this->help($command['definition']);
            return 0;
        }
        $input = $command['definition']->parse($tokens);
        $code = Invoker::call($command['handler'], $this->services, [$input, $this->output]);
        if ($code === null) {
            return 0;
        }
        if (!is_int($code) || $code < 0 || $code > 255) {
            throw new \LogicException('命令必须返回 0–255 的整数退出码');
        }
        return $code;
    }

    private function listCatalog(): void
    {
        $this->output->info('Loom Console');
        $this->output->line('  list  列出所有命令');
        $this->output->line('  help [command]  列出所有命令或显示指定命令的帮助');
        foreach ($this->commands as $command) {
            if (!$command['hidden']) {
                $this->output->line('  ' . $command['definition']->name . '  ' . $command['definition']->description);
            }
        }
    }

    private function help(Definition $definition): void
    {
        $this->output->info($definition->description);
        $this->output->line('用法：' . $definition->signature);
        $this->output->line();
        foreach ($definition->arguments as $name => $arg) {
            $this->output->line('  ' . $name . '  ' . $arg['description']);
        }
        foreach ($definition->options as $name => $option) {
            $this->output->line('  --' . $name . ($option['alias'] !== '' ? ', -' . $option['alias'] : '') . '  ' . $option['description']);
        }
        $this->output->line('  --help, -h  显示命令帮助');
    }
}
