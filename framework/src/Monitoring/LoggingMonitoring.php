<?php

declare(strict_types=1);

namespace Loom\Monitoring;

use Loom\Logging\Diary;

/**
 * 默认实现：在作用域内缓冲结构化事件，dispose() 时经 {@see Diary} 写出 `monitoring.session`（默认标准错误 JSON 行）。
 *
 * SQL 绑定值只做长度与类型摘要，避免日志泄露敏感数据。HTTP 正文只记录字节数。
 * 写入目标与格式由根容器上的 JournalWriter / JournalFormatter 决定，与 RequestLog 等共用一套日记管道。
 */
final class LoggingMonitoring implements Monitoring
{
    /** @var list<array<string, mixed>> */
    private array $entries = [];
    private bool $active = false;
    private float $started = 0.0;
    private ?MonitoringContext $context = null;
    private mixed $writer;
    private ?Diary $diary;

    /**
     * @param ?callable $writer 接收一行已序列化文本的回调；非 null 时优先于 Diary（兼容测试）。默认 null。
     * @param ?Diary $diary 未提供 writer 时写入应用日记。从容器解析时自动注入。默认 null。
     */
    public function __construct(?callable $writer = null, ?Diary $diary = null)
    {
        $this->writer = $writer;
        $this->diary = $diary;
    }

    public function begin(MonitoringContext $context): void
    {
        if (!$this->active) {
            $this->started = microtime(true);
            $this->active = true;
            $this->context = $context;
            $this->entries = [];
            return;
        }
        if ($this->context === null) {
            $this->context = $context;
            return;
        }
        $channel = $this->context->channel !== '' ? $this->context->channel : $context->channel;
        $this->context = new MonitoringContext($channel, array_merge($this->context->tags, $context->tags));
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function recordSql(string $sql, array $bindings, float $durationMs, ?int $rowCount = null): void
    {
        if (!$this->active) {
            return;
        }
        $this->entries[] = [
            'event' => 'database.sql',
            'sql' => $sql,
            'bindings' => self::summarizeBindings($bindings),
            'duration_ms' => round(max(0.0, $durationMs), 3),
            'row_count' => $rowCount,
        ];
    }

    public function recordHttpInput(array $input): void
    {
        if (!$this->active) {
            return;
        }
        $this->entries[] = ['event' => 'http.input'] + $input;
    }

    public function recordHttpOutput(array $output): void
    {
        if (!$this->active) {
            return;
        }
        $this->entries[] = ['event' => 'http.output'] + $output;
    }

    public function recordConsole(array $detail): void
    {
        if (!$this->active) {
            return;
        }
        $this->entries[] = ['event' => 'console.result'] + $detail;
    }

    public function recordEvent(string $name, array $data = []): void
    {
        if (!$this->active) {
            return;
        }
        $this->entries[] = ['event' => $name] + $data;
    }

    public function dispose(): void
    {
        if (!$this->active) {
            return;
        }
        $this->active = false;
        $context = $this->context;
        $duration = round(max(0.0, microtime(true) - $this->started) * 1000, 3);
        $fields = [
            'channel' => $context?->channel ?? 'unknown',
            'tags' => $context?->tags ?? [],
            'duration_ms' => $duration,
            'entries' => $this->entries,
        ];
        try {
            if ($this->writer !== null) {
                $writer = $this->writer;
                $writer(json_encode([
                    'time' => gmdate('Y-m-d\TH:i:s\Z'),
                    'event' => 'monitoring.session',
                ] + $fields, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES) . "\n");
            } elseif ($this->diary !== null) {
                $this->diary->event('monitoring.session', $fields);
            } else {
                $line = json_encode([
                    'time' => gmdate('Y-m-d\TH:i:s\Z'),
                    'event' => 'monitoring.session',
                ] + $fields, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES) . "\n";
                $stream = @fopen('php://stderr', 'w');
                if ($stream !== false) {
                    try {
                        @fwrite($stream, $line);
                    } finally {
                        fclose($stream);
                    }
                }
            }
        } catch (\Throwable) {
            // 监控写出失败不得影响业务收尾。
        }
        $this->entries = [];
        $this->context = null;
    }

    /**
     * @param array<int, mixed> $bindings
     * @return list<array{type: string, length: int}>
     */
    private static function summarizeBindings(array $bindings): array
    {
        $out = [];
        foreach ($bindings as $value) {
            if ($value === null) {
                $out[] = ['type' => 'null', 'length' => 0];
                continue;
            }
            if (is_bool($value)) {
                $out[] = ['type' => 'bool', 'length' => 1];
                continue;
            }
            if (is_int($value) || is_float($value)) {
                $out[] = ['type' => 'number', 'length' => 0];
                continue;
            }
            $text = is_string($value) ? $value : json_encode($value, JSON_THROW_ON_ERROR);
            $out[] = ['type' => is_string($value) ? 'string' : 'json', 'length' => strlen($text)];
        }
        return $out;
    }
}
