<?php

declare(strict_types=1);

namespace Loom\Console;

/**
 * 统一标准输出、错误输出、JSON 和文本表格，可注入写入回调以捕获输出。
 *
 * 构造参数：
 * - $writer（?callable）：可选 callable(string $text, bool $stderr): void；null 使用标准流。默认值为 null。
 *
 * 返回值：使用 new 构造时得到 Output 实例；注册到容器时由容器管理实例的创建与复用。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $output = new \Loom\Console\Output(); $output->line('完成');
 */
final class Output
{
    private mixed $writer;
    /**
     * 初始化 Output 实例及其依赖。统一标准输出、错误输出、JSON 和文本表格，可注入写入回调以捕获输出。
     *
     * @param ?callable $writer 可选 callable(string $text, bool $stderr): void；null 使用标准流。默认值为 null。
     * @return void 无直接返回值；通过 new 表达式取得初始化后的实例。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $output = new \Loom\Console\Output(); $output->line('完成');
     */
    public function __construct(?callable $writer = null)
    {
        $this->writer = $writer;
    }
    /**
     * 将原始文本交给注入回调或标准流，不自动追加换行。
     *
     * @param string $text 待输出的文本。
     * @param bool $stderr true 写标准错误，false 写标准输出。默认值为 false。
     * @return void 无返回值。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $output->write('处理中');
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
     * 向标准输出写入文本并追加换行。
     *
     * @param string $text 待输出的文本。默认值为 ''。
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
     * 将信息文本作为普通行写入标准输出，不添加颜色或前缀。
     *
     * @param string $text 待输出的文本。
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
     * 将错误文本及换行写入标准错误流或标记为错误的写入回调。
     *
     * @param string $text 待输出的文本。
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
     * 以保留中文、缩进的 JSON 格式输出数据，编码失败抛出 JsonException。
     *
     * @param mixed $value 可 JSON 编码的数据。
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
     * 输出竖线分隔表格，校验每行列数并把单元格内换行和制表符替换为空格。
     *
     * @param array $headers 表头文字列表。
     * @param array $rows 二维行数组，每行列数必须等于表头列数。
     * @return void 无返回值；列数不一致时抛出 LogicException。
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
