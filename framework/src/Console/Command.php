<?php

declare(strict_types=1);

namespace Loom\Console;

/**
 * 控制台命令基类，子类声明签名与描述并实现 handle；execute 临时绑定输入输出，结束后恢复旧绑定。
 *
 * 构造参数：无，本类型未声明构造参数。
 *
 * 返回值：抽象类不能直接实例化；创建具体子类后获得符合本类约定的对象。
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
     * 读取子类声明的命令签名。
     *
     * 参数：无。
     *
     * @return string 用于 Definition 解析的签名字符串。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $signature = $command->signature();
     */
    final public function signature(): string
    {
        return $this->signature;
    }
    /**
     * 读取子类声明的帮助描述。
     *
     * 参数：无。
     *
     * @return string 命令用途描述字符串。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $description = $command->description();
     */
    final public function description(): string
    {
        return $this->description;
    }
    /**
     * 判断命令是否应在命令列表中隐藏；隐藏不禁止直接调用。
     *
     * 参数：无。
     *
     * @return bool true 表示从列表隐藏，false 表示显示。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $hidden = $command->hidden();
     */
    final public function hidden(): bool
    {
        return $this->hidden;
    }
    /**
     * 由具体命令实现业务操作，通过基类辅助方法访问输入并输出结果。
     *
     * 参数：无。
     *
     * @return int 0 至 255 的退出码，0 表示成功。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $code = $command->execute($input, $output);
     */
    abstract public function handle(): int;
    /**
     * 为本次调用临时绑定 Input 与 Output，执行 handle 后在 finally 中恢复旧上下文。
     *
     * @param Input $input 按命令签名解析完成的 Console\Input。
     * @param Output $output 本次命令使用的 Console\Output。
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
     * 在命令执行期间读取已解析的位置参数，尚未执行时抛出 LogicException。
     *
     * @param string $name 签名中声明的位置参数或选项名称。
     * @return mixed 对应参数值。
     *
     * 内部调用示例（仅类内使用）：
     * @example $name = $this->argument('name');
     */
    protected function argument(string $name): mixed
    {
        if ($this->input === null) {
            throw new \LogicException('命令尚未执行');
        } return $this->input->argument($name);
    }
    /**
     * 在命令执行期间读取已解析选项，尚未执行时抛出 LogicException。
     *
     * @param string $name 签名中声明的位置参数或选项名称。
     * @return mixed 对应选项值。
     *
     * 内部调用示例（仅类内使用）：
     * @example $force = $this->option('force');
     */
    protected function option(string $name): mixed
    {
        if ($this->input === null) {
            throw new \LogicException('命令尚未执行');
        } return $this->input->option($name);
    }
    /**
     * 通过当前输出对象写入一行文本，仅在 execute 绑定输出期间使用。
     *
     * @param string $text 待输出文本。默认值为 ''。
     * @return void 无返回值。
     *
     * 内部调用示例（仅类内使用）：
     * @example $this->line('完成');
     */
    protected function line(string $text = ''): void
    {
        $this->output->line($text);
    }
    /**
     * 通过当前输出对象写入信息行。
     *
     * @param string $text 待输出文本。
     * @return void 无返回值。
     *
     * 内部调用示例（仅类内使用）：
     * @example $this->info('处理完成');
     */
    protected function info(string $text): void
    {
        $this->output->info($text);
    }
    /**
     * 通过当前输出对象写入错误行。
     *
     * @param string $text 待输出文本。
     * @return void 无返回值。
     *
     * 内部调用示例（仅类内使用）：
     * @example $this->error('处理失败');
     */
    protected function error(string $text): void
    {
        $this->output->error($text);
    }
    /**
     * 通过当前输出对象输出列数一致的文本表格。
     *
     * @param array $headers 表格列标题列表。
     * @param array $rows 表格行列表，列数与标题一致。
     * @return void 无返回值。
     *
     * 内部调用示例（仅类内使用）：
     * @example $this->table(['名称'], [['queue']]);
     */
    protected function table(array $headers, array $rows): void
    {
        $this->output->table($headers, $rows);
    }
    /**
     * 通过当前输出对象写入格式化 JSON。
     *
     * @param mixed $value 可 JSON 编码的输出数据。
     * @return void 无返回值。
     *
     * 内部调用示例（仅类内使用）：
     * @example $this->json(['ok' => true]);
     */
    protected function json(mixed $value): void
    {
        $this->output->json($value);
    }
}
