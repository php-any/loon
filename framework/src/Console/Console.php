<?php

declare(strict_types=1);

namespace Loom\Console;

/**
 * 登记闭包或 Command 子类，解析参数后执行，并把输入错误与其它异常收成稳定退出码。list 和 help 是保留命令；run 的嵌套深度达到 16 时不再执行。
 *
 * 构造参数：
 * - $output（?Output）：帮助、列表和错误的输出；null 时新建默认 Output。
 * - $container（?\Loom\Container）：解析 Command 子类的容器；null 时新建空容器。
 *
 * 返回值：还没有业务命令的 Console。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $console->command('ping', fn () => 0, '连通性检查'); $code = $console->run(['ping']);
 */
final class Console
{
    private array $commands = [];
    private \Loom\Container $container;
    private Output $output;
    private int $depth = 0;
    /**
     * 保存输出和容器。参数为 null 时分别新建 Output 或空 Container。不登记命令，也不读取 argv。
     *
     * @param ?Output $output 帮助、列表和错误要写入的对象；null 表示改用默认 Output。
     * @param ?\Loom\Container $container 要保存的服务容器；null 表示新建空容器。
     * @return void 无返回值，两个依赖已写在实例上。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $console = new \Loom\Console\Console($output, $container);
     */
    public function __construct(?Output $output = null, ?\Loom\Container $container = null)
    {
        $this->container = $container ?? new \Loom\Container();
        $this->output = $output ?? new Output();
    }
    /**
     * 返回构造时保存的容器。不新建容器，也不在这里登记服务。
     *
     * 参数：无。
     *
     * @return \Loom\Container 解析 Command 子类所用的容器。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $container = $console->container();
     */
    public function container(): \Loom\Container
    {
        return $this->container;
    }
    /**
     * 判断名称是保留命令 list、help，还是已经登记的命令。不查看参数；隐藏命令也算存在。
     *
     * @param string $name 命令名，不含参数和选项。
     * @return bool 保留名或已登记时为 true，否则为 false。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $exists = $console->has('ping');
     */
    public function has(string $name): bool
    {
        return in_array($name, ['list', 'help'], true) || isset($this->commands[$name]);
    }
    /**
     * 解析签名并登记闭包。名称是 list、help 或已被占用时抛出 LogicException；签名不合法时由 Definition 抛出 LogicException。
     *
     * @param string $signature 命令名加上参数、选项字段，例如 greet {name}。
     * @param callable $handler 执行时收到 Input 和 Output，应返回 0–255 的整数或 null。
     * @param string $description 列表和帮助里的说明；省略时为空字符串。
     * @return self 当前 Console，可继续登记。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $console->command('greet {name}', static function (Input $in, Output $out): int { $out->line($in->argument('name')); return 0; }, '问候用户');
     */
    public function command(string $signature, callable $handler, string $description = ''): self
    {
        $definition = new Definition($signature, $description);
        if (in_array($definition->name, ['list', 'help'], true) || isset($this->commands[$definition->name])) {
            throw new \LogicException('命令名重复或属于保留名称：' . $definition->name);
        }
        $this->commands[$definition->name] = ['definition' => $definition, 'handler' => $handler];
        return $this;
    }
    /**
     * 不调用命令构造函数，只读取子类声明的签名、描述和隐藏标记，再登记一个从容器取实例并 execute 的闭包。类不能实例化或不是 Command 子类时立刻抛出 LogicException。容器以后返回的不是 Command 时，要到该命令真正执行才抛出 LogicException。重复名称由 command 拒绝。
     *
     * @param string $class 容器能够构造的 Command 子类全名。
     * @return self 当前 Console。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $console->add(\Loom\Console\Commands\DoctorCommand::class);
     */
    public function add(string $class): self
    {
        $reflection = new \ReflectionClass($class);
        if (!$reflection->isInstantiable() || !$reflection->isSubclassOf(Command::class)) {
            throw new \LogicException('命令类必须继承 Command');
        }
        // 元数据来自声明，查看帮助和非法参数不能构造命令及其业务依赖。
        // Origami 未提供 getDefaultProperties；无构造函数的元数据对象不解析服务。
        $metadata = $reflection->newInstanceWithoutConstructor();
        $this->command($metadata->signature(), function (Input $input, Output $output) use ($class) {
            $command = $this->container->get($class);
            if (!($command instanceof Command)) {
                throw new \LogicException('命令工厂必须返回 Command');
            }
            return $command->execute($input, $output);
        }, $metadata->description());
        $definition = new Definition($metadata->signature(), $metadata->description());
        $this->commands[$definition->name]['hidden'] = $metadata->hidden();
        return $this;
    }
    /**
     * 把描述、用法、每个位置参数、每个选项，以及固定的 --help/-h 行写到构造时保存的输出。某一组为空就跳过对应循环，描述和用法仍会写出。
     *
     * @param Definition $definition 已经拆好的签名，含描述、参数和选项。
     * @return void 无返回值，文本已经写出。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $this->help(new Definition('greet {name : 用户名} {--loud : 大声}', '问候用户'));
     */
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
    /**
     * 执行 list、help 或已登记命令。$argv 为 null 时改读 $GLOBALS['argv'] 并去掉程序名。进入时深度已达 16 就写错误并返回 1，这次不加深，也不进入后面的 finally。否则先加深，并在 finally 里减回去。list 若还带参数，或 help 不是恰好再跟一个命令名，都按输入错误返回 2；未知命令同样返回 2。在单独的 -- 之前出现 --help 或 -h 时只打印帮助并返回 0，不调用处理器。处理器返回 null 视为 0；不是 0–255 的整数时抛出 LogicException，被本方法收成退出码 1。InputError 返回 2，其它异常返回 1。隐藏命令不会出现在 list 里。
     *
     * @param ?array $argv 第一项是命令名、其余是参数和选项；null 表示改读全局 argv 并跳过可执行文件名。
     * @return int 成功时为处理器退出码或 0；输入错误为 2；嵌套过深或其它异常为 1。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $code = $console->run(['greet', '小明', '--help']);
     */
    public function run(?array $argv = null): int
    {
        if ($argv === null) {
            $argv = array_slice($GLOBALS['argv'], 1);
        }
        if ($this->depth >= 16) {
            $this->output->error('命令嵌套调用次数超过上限');
            return 1;
        }
        $this->depth++;
        try {
            $name = $argv[0] ?? 'list';
            if ($name === '--help' || $name === '-h') {
                $name = 'list';
            }
            if ($name === 'list') {
                if (count($argv) > 1) {
                    throw new InputError('list 命令不接受参数');
                } $this->output->info('Loom Console');
                $this->output->line('  list  列出所有命令');
                $this->output->line('  help <command>  显示帮助');
                foreach ($this->commands as $command) {
                    if ($command['hidden'] ?? false) {
                        continue;
                    }
                    $d = $command['definition'];
                    $this->output->line('  ' . $d->name . '  ' . $d->description);
                } return 0;
            }
            $help = $name === 'help';
            if ($help) {
                if (count($argv) !== 2) {
                    throw new InputError('用法：help <command>');
                } $name = $argv[1];
            }
            if (!isset($this->commands[$name])) {
                throw new InputError('未知命令：' . $name . '。请执行 list 查看可用命令。');
            }
            $command = $this->commands[$name];
            $tokens = array_slice($argv, 1);
            foreach ($tokens as $token) {
                if ($token === '--') {
                    break;
                } if ($token === '--help' || $token === '-h') {
                    $help = true;
                }
            }
            if ($help) {
                $this->help($command['definition']);
                return 0;
            }
            $input = $command['definition']->parse($tokens);
            $handler = $command['handler'];
            $code = $handler($input, $this->output);
            if ($code === null) {
                return 0;
            } if (!is_int($code) || $code < 0 || $code > 255) {
                throw new \LogicException('命令必须返回 0–255 的整数退出码');
            } return $code;
        } catch (InputError $error) {
            $this->output->error($error->getMessage());
            return 2;
        } catch (\Throwable $error) {
            $this->output->error($error->getMessage());
            return 1;
        } finally {
            $this->depth--;
        }
    }
    /**
     * 把给定参数原样交给 run，深度、帮助和退出码都按 run 处理。参数不能省略，也不会改读全局 argv。
     *
     * @param array $argv 第一项是命令名、其余是参数和选项的字符串列表。
     * @return int run 返回的退出码。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $code = $console->call(['ping']);
     */
    public function call(array $argv): int
    {
        return $this->run($argv);
    }
}
