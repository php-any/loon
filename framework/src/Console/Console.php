<?php

declare(strict_types=1);

namespace Loom\Console;

/**
 * 注册闭包或 Command 类命令，解析输入、显示帮助并把错误转换为稳定退出码。
 *
 * 构造参数：
 * - $output（?Output）：输出适配器；null 时创建默认 Output。默认值为 null。
 * - $container（?\Loom\Container）：命令实例使用的容器；null 时创建新容器。默认值为 null。
 *
 * 返回值：使用 new 构造时得到 Console 实例；注册到容器时由容器管理实例的创建与复用。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $console = new \Loom\Console\Console(); $console->command('ping', fn () => 0, '连通性检查'); $console->run(['ping']);
 */
final class Console
{
    private array $commands = [];
    private \Loom\Container $container;
    private Output $output;
    private int $depth = 0;
    /**
     * 初始化 Console 实例及其依赖。注册闭包或 Command 类命令，解析输入、显示帮助并把错误转换为稳定退出码。
     *
     * @param ?Output $output 输出适配器；null 时创建默认 Output。默认值为 null。
     * @param ?\Loom\Container $container 命令实例使用的容器；null 时创建新容器。默认值为 null。
     * @return void 无直接返回值；通过 new 表达式取得初始化后的实例。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $console = new \Loom\Console\Console(); $console->command('ping', fn () => 0, '连通性检查'); $console->run(['ping']);
     */
    public function __construct(?Output $output = null, ?\Loom\Container $container = null)
    {
        $this->container = $container ?? new \Loom\Container();
        $this->output = $output ?? new Output();
    }
    /**
     * 取得控制台解析命令类时使用的容器。
     *
     * 参数：无。
     *
     * @return \Loom\Container 当前服务容器。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $services = $console->services();
     */
    public function services(): \Loom\Container
    {
        return $this->container;
    }
    /**
     * 检查内置 list/help 或业务命令是否存在。
     *
     * @param string $name 要检查的命令名称，不包含参数。
     * @return bool 存在返回 true，否则 false。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $exists = $console->has('ping');
     */
    public function has(string $name): bool
    {
        return in_array($name, ['list', 'help'], true) || isset($this->commands[$name]);
    }
    /**
     * 解析签名并注册闭包命令，拒绝重复名称或占用 list/help。
     *
     * @param string $signature 命令名称及参数/选项签名。
     * @param callable $handler 接收 Console\Input 与 Console\Output 的回调，返回 0–255 整数或 null。
     * @param string $description 显示在命令列表与帮助中的说明。默认值为 ''。
     * @return self 当前 Console，支持链式注册。
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
     * 由容器解析 Command 子类并把其签名、描述和执行回调注册到控制台。
     *
     * @param string $class 可由容器构造的 Command 子类完整类名。
     * @return self 当前 Console；非 Command 对象会报错。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $console->add($commandClass);
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
     * 输出命令描述、用法、参数及选项帮助。
     *
     * @param Definition $definition 已解析的命令签名定义。
     * @return void 无返回值。
     *
     * 内部调用示例（仅类内使用）：
     * @example $this->help($definition);
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
     * 处理命令列表、帮助或执行请求，限制嵌套深度并统一错误退出码。
     *
     * @param ?array $argv 包含命令名及参数的字符串列表；run 传 null 时读取全局 argv 并跳过可执行文件名。默认值为 null。
     * @return int 成功为处理器退出码或 0；输入错误为 2，其他异常为 1。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $code = $console->run(['help', 'greet']);
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
     * 在当前控制台内嵌套执行另一命令，沿用 run 的解析和退出码规则。
     *
     * @param array $argv 包含命令名及参数的字符串列表；run 传 null 时读取全局 argv 并跳过可执行文件名。
     * @return int 被调用命令的退出码。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $code = $console->call(['ping']);
     */
    public function call(array $argv): int
    {
        return $this->run($argv);
    }
}
