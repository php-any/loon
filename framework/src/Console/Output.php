<?php

declare(strict_types=1);

namespace Loom\Console;

/**
 * 把文本、JSON 和简单表格写到标准输出或标准错误。构造时传入写入回调后，后续输出都走该回调，不再碰标准流。
 *
 * 构造参数：
 * - $writer（?callable）：接收文本和是否标准错误的回调；null 时写真实标准流。
 *
 * 返回值：按该方式写出的 Output。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $output = new \Loom\Console\Output();
 */
final class Output
{
    private mixed $writer;
    /**
     * 保存写入回调。null 表示以后的 write 走标准输出或标准错误，构造时不打开流。
     *
     * @param ?callable $writer 形如 function (string $text, bool $stderr): void 的回调；null 表示使用标准流。省略时为 null。
     * @return void 无返回值，回调已保存。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $output = new \Loom\Console\Output(static function (string $text, bool $stderr): void { echo $text; });
     */
    public function __construct(?callable $writer = null)
    {
        $this->writer = $writer;
    }
    /**
     * 写出一段原文，不追加换行。已有回调时把文本和 $stderr 交给回调；否则 $stderr 为 true 时写入 php://stderr，为 false 时 echo。走标准错误时每次都重新打开 php://stderr。
     *
     * @param string $text 原样写出的文本，可以不含换行。
     * @param bool $stderr 为 true 时走标准错误，或让回调按错误处理；省略时为 false。
     * @return void 无返回值。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $output->write("失败\n", true);
     */
    public function write(string $text, bool $stderr = false): void
    {
        if ($this->writer !== null) {
            $writer = $this->writer;
            $writer($text, $stderr);
        } else {
            if ($stderr) {
                fwrite(fopen('php://stderr', 'w'), $text);
            } else {
                echo $text;
            }
        }
    }
    /**
     * 在文本末尾加上换行后写入标准输出，不走标准错误。
     *
     * @param string $text 换行之前的正文；省略时只写出一个换行。
     * @return void 无返回值。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $output->line('完成');
     */
    public function line(string $text = ''): void
    {
        $this->write($text . "\n");
    }
    /**
     * 把信息当作普通一行写到标准输出，不加颜色，也不加前缀。
     *
     * @param string $text 这一行的信息正文。
     * @return void 无返回值。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $output->info('迁移完成');
     */
    public function info(string $text): void
    {
        $this->line($text);
    }
    /**
     * 在文本末尾加上换行后写入标准错误。注入了回调时，回调收到的第二个参数为 true。
     *
     * @param string $text 错误正文；本方法会再补一个换行。
     * @return void 无返回值。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $output->error('操作失败');
     */
    public function error(string $text): void
    {
        $this->write($text . "\n", true);
    }
    /**
     * 用抛出错误、不转义中文和缩进把值编成 JSON，再作为一行写到标准输出。不能编码时 json_encode 抛出 JsonException，这一行不会写出。
     *
     * @param mixed $value 要编码的数组、标量或对象。
     * @return void 无返回值。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $output->json(['ok' => true]);
     */
    public function json(mixed $value): void
    {
        $this->line(json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }
    /**
     * 先写表头和一条分隔线，再逐行写出单元格。某一行的列数与表头不同时抛出 LogicException，该行以及后面的行不再写出。单元格里的回车、换行和制表符换成空格。分隔线长度至少为 3。
     *
     * @param array $headers 列标题，顺序就是列顺序。
     * @param array $rows 每行是与表头等长的单元格列表；空数组时只写表头和分隔线。
     * @return void 无返回值。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $output->table(['名称', '状态'], [['queue', '运行中']]);
     */
    public function table(array $headers, array $rows): void
    {
        $this->line(implode(' | ', $headers));
        $this->line(str_repeat('-', max(3, strlen(implode(' | ', $headers)))));
        foreach ($rows as $row) {
            if (count($row) !== count($headers)) {
                throw new \LogicException('表格行的列数与表头不一致');
            } $cells = [];
            foreach ($row as $cell) {
                $cells[] = str_replace(["\r", "\n", "\t"], ' ', (string) $cell);
            } $this->line(implode(' | ', $cells));
        }
    }
}
