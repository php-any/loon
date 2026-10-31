<?php
declare(strict_types=1);
namespace Loom\Console;

final class InputError extends \RuntimeException {}

final class Input
{
    private array $arguments;
    private array $options;
    public function __construct(array $arguments, array $options) { $this->arguments = $arguments; $this->options = $options; }
    public function argument(string $name): mixed { if (!array_key_exists($name, $this->arguments)) { throw new \LogicException('未知参数：' . $name); } return $this->arguments[$name]; }
    public function option(string $name): mixed { if (!array_key_exists($name, $this->options)) { throw new \LogicException('未知选项：' . $name); } return $this->options[$name]; }
}

final class Output
{
    private mixed $writer;
    public function __construct(?callable $writer = null) { $this->writer = $writer; }
    public function write(string $text, bool $stderr = false): void
    {
        if ($this->writer !== null) { $writer = $this->writer; $writer($text, $stderr); }
        else { \Loom\console_io(json_encode(['op' => 'write', 'text' => $text, 'stderr' => $stderr], JSON_THROW_ON_ERROR)); }
    }
    public function line(string $text = ''): void { $this->write($text . "\n"); }
    public function info(string $text): void { $this->line($text); }
    public function error(string $text): void { $this->write($text . "\n", true); }
    public function json(mixed $value): void { $this->line(json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)); }
    public function table(array $headers, array $rows): void
    {
        $this->line(implode(' | ', $headers)); $this->line(str_repeat('-', max(3, strlen(implode(' | ', $headers)))));
        foreach ($rows as $row) { if (count($row) !== count($headers)) { throw new \LogicException('表格行的列数与表头不一致'); } $cells = []; foreach ($row as $cell) { $cells[] = str_replace(["\r", "\n", "\t"], ' ', (string) $cell); } $this->line(implode(' | ', $cells)); }
    }
}

abstract class Command
{
    protected string $signature = '';
    protected string $description = '';
    private ?Input $input = null;
    private ?Output $output = null;
    final public function signature(): string { return $this->signature; }
    final public function description(): string { return $this->description; }
    abstract public function handle(): int;
    final public function execute(Input $input, Output $output): int
    {
        $oldInput = $this->input; $oldOutput = $this->output; $this->input = $input; $this->output = $output;
        try { return $this->handle(); } finally { $this->input = $oldInput; $this->output = $oldOutput; }
    }
    protected function argument(string $name): mixed { if ($this->input === null) { throw new \LogicException('命令尚未执行'); } return $this->input->argument($name); }
    protected function option(string $name): mixed { if ($this->input === null) { throw new \LogicException('命令尚未执行'); } return $this->input->option($name); }
    protected function line(string $text = ''): void { $this->output->line($text); }
    protected function info(string $text): void { $this->output->info($text); }
    protected function error(string $text): void { $this->output->error($text); }
    protected function table(array $headers, array $rows): void { $this->output->table($headers, $rows); }
    protected function json(mixed $value): void { $this->output->json($value); }
}

final class Definition
{
    public string $name;
    public string $signature;
    public string $description;
    public array $arguments = [];
    public array $options = [];
    private array $aliases = [];
    public function __construct(string $signature, string $description)
    {
        $this->signature = trim($signature); $this->description = $description;
        if (!preg_match('/^([a-z][a-z0-9:_-]*)(.*)$/sD', $this->signature, $match)) { throw new \LogicException('命令签名无效'); }
        $this->name = $match[1]; $tail = $match[2];
        if (trim(preg_replace('/\{[^{}]+\}/', '', $tail)) !== '') { throw new \LogicException('命令签名字段无效'); }
        preg_match_all('/\{([^{}]+)\}/', $tail, $fields);
        $optional = false; $variadic = false;
        foreach ($fields[1] as $field) {
            $pieces = preg_split('/\s+:\s*/', $field, 2); $spec = trim($pieces[0]); $description = trim($pieces[1] ?? '');
            if (substr($spec, 0, 2) === '--') {
                $parts = explode('=', substr($spec, 2), 2); $names = explode('|', $parts[0]);
                if (count($names) > 2) { throw new \LogicException('选项别名无效'); }
                $alias = count($names) === 2 ? $names[0] : ''; $name = count($names) === 2 ? $names[1] : $names[0];
                if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_-]*$/D', $name) || in_array($name, ['help'], true) || isset($this->options[$name])) { throw new \LogicException('选项无效或重复：' . $name); }
                if ($alias !== '') { if (!preg_match('/^[a-zA-Z]$/D', $alias) || $alias === 'h' || isset($this->aliases[$alias])) { throw new \LogicException('选项别名无效或重复'); } $this->aliases[$alias] = $name; }
                $value = count($parts) === 2; $array = $value && $parts[1] === '*';
                $this->options[$name] = ['value' => $value, 'array' => $array, 'default' => $array ? [] : ($value ? ($parts[1] === '' ? null : $parts[1]) : false), 'description' => $description, 'alias' => $alias];
            } else {
                if ($variadic) { throw new \LogicException('数组参数必须放在最后'); }
                $parts = explode('=', $spec, 2); $name = $parts[0]; $array = substr($name, -1) === '*'; if ($array) { $name = substr($name, 0, -1); }
                $isOptional = substr($name, -1) === '?'; if ($isOptional) { $name = substr($name, 0, -1); }
                if (count($parts) === 2) { if ($array || $isOptional) { throw new \LogicException('参数默认值无效'); } $isOptional = true; }
                if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_-]*$/D', $name) || isset($this->arguments[$name])) { throw new \LogicException('参数无效或重复'); }
                if ($optional && !$isOptional) { throw new \LogicException('必填参数不能放在可选参数之后'); }
                $optional = $isOptional; $variadic = $array;
                $this->arguments[$name] = ['optional' => $isOptional, 'array' => $array, 'default' => $array ? [] : ($parts[1] ?? null), 'description' => $description];
            }
        }
    }
    public function parse(array $tokens): Input
    {
        $options = []; foreach ($this->options as $name => $definition) { $options[$name] = $definition['default']; }
        $positionals = []; $end = false;
        for ($i = 0; $i < count($tokens); $i++) {
            $token = $tokens[$i];
            if (!is_string($token)) { throw new InputError('命令参数必须是字符串'); }
            if (!$end && $token === '--') { $end = true; continue; }
            if (!$end && strlen($token) > 1 && $token[0] === '-' && !preg_match('/^-\d+(\.\d+)?$/D', $token)) {
                $provided = false; $value = null;
                if (substr($token, 0, 2) === '--') { $parts = explode('=', substr($token, 2), 2); $name = $parts[0]; $provided = count($parts) === 2; $value = $parts[1] ?? null; }
                else { $alias = $token[1]; if (!isset($this->aliases[$alias])) { throw new InputError('未知选项：-' . $alias); } $name = $this->aliases[$alias]; if (strlen($token) > 2) { $provided = true; $value = substr($token, 2); if ($value[0] === '=') { $value = substr($value, 1); } } }
                if (!isset($this->options[$name])) { throw new InputError('未知选项：--' . $name); }
                $definition = $this->options[$name];
                if (!$definition['value']) { if ($provided) { throw new InputError('布尔选项不能指定值：--' . $name); } $options[$name] = true; continue; }
                if (!$provided) { if (!isset($tokens[$i + 1]) || $tokens[$i + 1] === '--' || (substr($tokens[$i + 1], 0, 1) === '-' && !preg_match('/^-\d+(\.\d+)?$/D', $tokens[$i + 1]))) { throw new InputError('缺少选项值：--' . $name); } $value = $tokens[++$i]; }
                if ($definition['array']) { $options[$name][] = $value; } else { $options[$name] = $value; }
            } else { $positionals[] = $token; }
        }
        $arguments = []; $index = 0;
        foreach ($this->arguments as $name => $definition) {
            if ($definition['array']) { $value = array_slice($positionals, $index); $index = count($positionals); if (!$definition['optional'] && $value === []) { throw new InputError('缺少必填参数：' . $name); } }
            elseif ($index < count($positionals)) { $value = $positionals[$index++]; }
            elseif (!$definition['optional']) { throw new InputError('缺少必填参数：' . $name); }
            else { $value = $definition['default']; }
            $arguments[$name] = $value;
        }
        if ($index < count($positionals)) { throw new InputError('参数数量过多'); }
        return new Input($arguments, $options);
    }
}

final class Console
{
    private array $commands = [];
    private \Loom\Container $container;
    private Output $output;
    private int $depth = 0;
    public function __construct(?Output $output = null) { $this->container = new \Loom\Container(); $this->output = $output ?? new Output(); }
    public function services(): \Loom\Container { return $this->container; }
    public function has(string $name): bool { return in_array($name, ['list', 'help'], true) || isset($this->commands[$name]); }
    public function command(string $signature, callable $handler, string $description = ''): self
    {
        $definition = new Definition($signature, $description);
        if (in_array($definition->name, ['list', 'help'], true) || isset($this->commands[$definition->name])) { throw new \LogicException('命令名重复或属于保留名称：' . $definition->name); }
        $this->commands[$definition->name] = ['definition' => $definition, 'handler' => $handler]; return $this;
    }
    public function add(string $class): self
    {
        $command = $this->container->get($class); if (!($command instanceof Command)) { throw new \LogicException('命令类必须继承 Command'); }
        return $this->command($command->signature(), function (Input $input, Output $output) use ($command) { return $command->execute($input, $output); }, $command->description());
    }
    private function help(Definition $definition): void
    {
        $this->output->info($definition->description); $this->output->line('用法：' . $definition->signature); $this->output->line();
        foreach ($definition->arguments as $name => $arg) { $this->output->line('  ' . $name . '  ' . $arg['description']); }
        foreach ($definition->options as $name => $option) { $this->output->line('  --' . $name . ($option['alias'] !== '' ? ', -' . $option['alias'] : '') . '  ' . $option['description']); }
        $this->output->line('  --help, -h  显示命令帮助');
    }
    public function run(?array $argv = null): int
    {
        if ($argv === null) { $argv = json_decode(\Loom\console_io('{"op":"argv"}'), true, 64, JSON_THROW_ON_ERROR); }
        if ($this->depth >= 16) { $this->output->error('命令嵌套调用次数超过上限'); return 1; }
        $this->depth++;
        try {
            $name = $argv[0] ?? 'list';
            if ($name === '--help' || $name === '-h') { $name = 'list'; }
            if ($name === 'list') { if (count($argv) > 1) { throw new InputError('list 命令不接受参数'); } $this->output->info('Loom Console'); $this->output->line('  list  列出所有命令'); $this->output->line('  help <command>  显示帮助'); foreach ($this->commands as $command) { $d = $command['definition']; $this->output->line('  ' . $d->name . '  ' . $d->description); } return 0; }
            $help = $name === 'help'; if ($help) { if (count($argv) !== 2) { throw new InputError('用法：help <command>'); } $name = $argv[1]; }
            if (!isset($this->commands[$name])) { throw new InputError('未知命令：' . $name . '。请执行 list 查看可用命令。'); }
            $command = $this->commands[$name]; $tokens = array_slice($argv, 1);
            foreach ($tokens as $token) { if ($token === '--') { break; } if ($token === '--help' || $token === '-h') { $help = true; } }
            if ($help) { $this->help($command['definition']); return 0; }
            $input = $command['definition']->parse($tokens); $handler = $command['handler']; $code = $handler($input, $this->output);
            if ($code === null) { return 0; } if (!is_int($code) || $code < 0 || $code > 255) { throw new \LogicException('命令必须返回 0–255 的整数退出码'); } return $code;
        } catch (InputError $error) { $this->output->error($error->getMessage()); return 2; }
        catch (\Throwable $error) { $this->output->error($error->getMessage()); return 1; }
        finally { $this->depth--; }
    }
    public function call(array $argv): int { return $this->run($argv); }
}
