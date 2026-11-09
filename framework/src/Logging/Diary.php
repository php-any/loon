<?php

declare(strict_types=1);

namespace Loom\Logging;

/**
 * 应用日记核心：组合 Formatter 与 Writer，对外提供结构化 event API。写入失败被吞掉，不中断业务。
 *
 * 构造参数：
 * - $writer（JournalWriter）：序列化后的输出目标。
 * - $formatter（JournalFormatter）：条目到文本的转换。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $diary->event('payment.settled', ['order_id' => 42]);
 */
final class Diary
{
    private JournalWriter $writer;
    private JournalFormatter $formatter;

    /**
     * @param JournalWriter $writer 日记写入目标。
     * @param JournalFormatter $formatter 日记格式化器。
     */
    public function __construct(JournalWriter $writer, JournalFormatter $formatter)
    {
        $this->writer = $writer;
        $this->formatter = $formatter;
    }

    /**
     * 记录一条带事件名的日记，默认级别为 info。
     *
     * @param string $event 事件标识。
     * @param array<string, mixed> $fields 与事件相关的字段。
     * @param string $level 日记级别。默认 info。
     */
    public function event(string $event, array $fields = [], string $level = 'info'): void
    {
        $this->record($level, $event, $fields);
    }

    /**
     * 记录一条完整日记。格式化后交给 Writer；任一步失败均被忽略。
     *
     * @param string $level 日记级别。
     * @param string $event 事件标识。
     * @param array<string, mixed> $fields 附加字段。
     */
    public function record(string $level, string $event, array $fields = []): void
    {
        try {
            $line = $this->formatter->format(JournalEntry::now($level, $event, $fields));
            $this->writer->write($line);
        } catch (\Throwable) {
            // 日记目标不可用不得中断业务路径。
        }
    }
}
