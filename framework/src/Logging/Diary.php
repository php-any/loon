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
    private JournalSink $sink;

    /**
     * @param JournalWriter|JournalSink $writerOrSink 单写入目标，或 stack 等多目标 sink。
     * @param ?JournalFormatter $formatter writerOrSink 为 JournalWriter 时必填。
     * @param string $minimumLevel 空表示不过滤；非空时低于该级别的条目被丢弃。
     */
    public function __construct(
        JournalWriter|JournalSink $writerOrSink,
        ?JournalFormatter $formatter = null,
        private string $minimumLevel = '',
    ) {
        $this->sink = $writerOrSink instanceof JournalSink
            ? $writerOrSink
            : new LaneJournalSink(
                $formatter ?? throw new \InvalidArgumentException('Diary requires JournalFormatter with JournalWriter'),
                $writerOrSink,
            );
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
        if (!$this->passesLevel($level)) {
            return;
        }
        try {
            $this->sink->emit(JournalEntry::now($level, $event, $fields));
        } catch (\Throwable) {
            // 日记目标不可用不得中断业务路径。
        }
    }

    private function passesLevel(string $level): bool
    {
        if ($this->minimumLevel === '') {
            return true;
        }
        return self::levelRank($level) >= self::levelRank($this->minimumLevel);
    }

    private static function levelRank(string $level): int
    {
        return match (strtolower($level)) {
            'debug' => 10,
            'info' => 20,
            'notice' => 30,
            'warning' => 40,
            'error' => 50,
            'critical' => 60,
            default => 20,
        };
    }
}
