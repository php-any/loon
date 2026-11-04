<?php

declare(strict_types=1);

namespace Loom\Console;

/**
 * 命令基类。子类用属性给出签名、描述和是否隐藏，并实现 handle。execute 只在这一次调用期间绑定输入和输出，离开时恢复进入前的绑定。
 *
 * 构造参数：无，本类不声明构造函数。
 *
 * 返回值：抽象类，只能得到具体子类的实例。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example final class PingCommand extends \Loom\Console\Command { protected string $signature = 'ping'; public function handle(): int { $this->info('pong'); return 0; } }
 */
abstract class Command
{
    protected string $signature = '';
    protected string $description = '';
    protected bool $hidden = false;
    private ?Input $input = null;
    private ?Output $output = null;
    /**
     * 返回子类声明的 $signature 原文。不解析，也不检查是否为空。
     *
     * 参数：无。
     *
     * @return string 交给 Definition 的签名字符串。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $signature = $command->signature();
     */
    final public function signature(): string
    {
        return $this->signature;
    }
    /**
     * 返回子类声明的 $description。没有写描述时就是空字符串。
     *
     * 参数：无。
     *
     * @return string 列表和帮助使用的描述。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $description = $command->description();
     */
    final public function description(): string
    {
        return $this->description;
    }
    /**
     * 返回子类声明的 $hidden。true 只让 list 跳过它，不阻止按名称执行。
     *
     * 参数：无。
     *
     * @return bool 为 true 时命令列表不展示该命令。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $hidden = $command->hidden();
     */
    final public function hidden(): bool
    {
        return $this->hidden;
    }
    /**
     * 子类在这里完成命令业务，并由 execute 调用。execute 会先绑好输入和输出。若没经过 execute 就调用 argument 或 option，会抛出 LogicException；写输出的辅助方法则没有 Output 可用。返回值须是 0–255 的退出码，0 表示成功。
     *
     * 参数：无。
     *
     * @return int 交给 execute 的退出码。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $code = $command->handle();
     */
    abstract public function handle(): int;
    /**
     * 先记住原来的输入和输出，绑上这一次的 Input 与 Output，调用 handle，再在 finally 里恢复旧绑定。handle 抛出的异常会在绑定恢复之后继续向外抛。返回值原样交给调用方。
     *
     * @param Input $input 已经按签名解析好的参数和选项。
     * @param Output $output 这一次命令写入标准输出和标准错误的对象。
     * @return int handle 返回的退出码。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $code = $command->execute($input, $output);
     */
    final public function execute(Input $input, Output $output): int
    {
        $oldInput = $this->input;
        $oldOutput = $this->output;
        $this->input = $input;
        $this->output = $output;
        try {
            return $this->handle();
        } finally {
            $this->input = $oldInput;
            $this->output = $oldOutput;
        }
    }
    /**
     * 从这次 execute 绑上的 Input 读取位置参数。还没有绑输入时抛出 LogicException，消息为「命令尚未执行」。名称不在输入里时，由 Input 抛出 LogicException。不读取选项。
     *
     * @param string $name 签名中的位置参数名。
     * @return mixed 该参数的字符串、数组或默认值。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $name = $this->argument('name');
     */
    protected function argument(string $name): mixed
    {
        if ($this->input === null) {
            throw new \LogicException('命令尚未执行');
        } return $this->input->argument($name);
    }
    /**
     * 从这次 execute 绑上的 Input 读取选项。还没有绑输入时抛出 LogicException，消息为「命令尚未执行」。名称不在输入里时，由 Input 抛出 LogicException。不读取位置参数。
     *
     * @param string $name 签名中的选项全名，不带 --。
     * @return mixed 布尔开关、字符串、数组或该选项的默认值。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $force = $this->option('force');
     */
    protected function option(string $name): mixed
    {
        if ($this->input === null) {
            throw new \LogicException('命令尚未执行');
        } return $this->input->option($name);
    }
    /**
     * 把一行文本交给当前绑上的 Output。必须在 execute 之内调用，否则没有 Output 可写。
     *
     * @param string $text 换行前的正文；省略时交给输出的是空字符串。
     * @return void 无返回值。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $this->line('完成');
     */
    protected function line(string $text = ''): void
    {
        $this->output->line($text);
    }
    /**
     * 把信息文本交给当前绑上的 Output::info。必须在 execute 之内调用，否则没有 Output 可写。
     *
     * @param string $text 要显示的信息正文。
     * @return void 无返回值。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $this->info('处理完成');
     */
    protected function info(string $text): void
    {
        $this->output->info($text);
    }
    /**
     * 把错误文本交给当前绑上的 Output::error，从而写到标准错误。必须在 execute 之内调用，否则没有 Output 可写。
     *
     * @param string $text 要显示的错误正文。
     * @return void 无返回值。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $this->error('处理失败');
     */
    protected function error(string $text): void
    {
        $this->output->error($text);
    }
    /**
     * 把表头和各行交给当前绑上的 Output 画成文本表。某一行列数与表头不同时，由 Output 抛出 LogicException。必须在 execute 之内调用。
     *
     * @param array $headers 从左到右的列标题。
     * @param array $rows 每一项是一行，列数须与表头相同。
     * @return void 无返回值。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $this->table(['名称'], [['queue']]);
     */
    protected function table(array $headers, array $rows): void
    {
        $this->output->table($headers, $rows);
    }
    /**
     * 把值交给当前绑上的 Output 写成 JSON。值不能编码时，由 Output 抛出 JsonException。必须在 execute 之内调用。
     *
     * @param mixed $value 要编码的数组、标量或对象。
     * @return void 无返回值。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $this->json(['ok' => true]);
     */
    protected function json(mixed $value): void
    {
        $this->output->json($value);
    }
}
