<?php

declare(strict_types=1);

namespace Loom\Monitoring;

use Loom\Database;
use Loom\Logging\Diary;
use Loom\ServiceScope;

/**
 * 默认 Monitoring：按 config/monitoring.php 与 MonitoringRegistration 采集、过滤并落 Diary / 数据库。
 */
final class ConfigurableMonitoring implements Monitoring
{
    /** @var list<array<string, mixed>> */
    private array $entries = [];
    private bool $active = false;
    private float $started = 0.0;
    private ?MonitoringContext $context = null;
    private ?ServiceScope $scope = null;

    public function __construct(
        private readonly MonitoringOptions $options,
        private readonly MonitoringRegistration $registration,
        private readonly ?Diary $diary,
        private readonly ?MonitoringStore $store,
        private readonly mixed $testWriter = null,
    ) {
    }

    public function bindScope(ServiceScope $scope): void
    {
        $this->scope = $scope;
    }

    public function begin(MonitoringContext $context): void
    {
        if (!$this->active) {
            $this->started = microtime(true);
            $this->active = true;
            $this->context = $context;
            $this->entries = [];
            if ($this->scope !== null) {
                foreach ($this->registration->watchers($this->options) as $watcher) {
                    try {
                        $watcher->start($this->scope, $this);
                    } catch (\Throwable) {
                    }
                }
            }
            return;
        }
        if ($this->context === null) {
            $this->context = $context;
            return;
        }
        $channel = $this->context->channel;
        if ($context->channel !== '' && $context->channel !== $this->context->channel) {
            $channel = $context->channel;
        }
        $this->context = new MonitoringContext($channel, array_merge($this->context->tags, $context->tags), $context->incomingCorrelationId());
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function recordSql(string $sql, array $bindings, float $durationMs, ?int $rowCount = null): void
    {
        if (!$this->active || !$this->options->watcherEnabled('sql')) {
            return;
        }
        $slow = (float)($this->options->watcher('sql')['slow'] ?? 0);
        if ($slow > 0 && $durationMs < $slow) {
            return;
        }
        $this->entries[] = [
            'event' => 'database.sql',
            'sql' => $sql,
            'bindings' => LoggingMonitoring::summarizeBindings($bindings),
            'duration_ms' => round(max(0.0, $durationMs), 3),
            'row_count' => $rowCount,
        ];
    }

    public function recordHttpInput(array $input): void
    {
        if (!$this->active || !$this->options->watcherEnabled('http')) {
            return;
        }
        $ignore = $this->options->watcher('http')['ignore_methods'] ?? [];
        $method = strtoupper((string)($input['method'] ?? ''));
        if (is_array($ignore) && $ignore !== [] && in_array($method, array_map('strtoupper', array_map('strval', $ignore)), true)) {
            return;
        }
        $this->entries[] = ['event' => 'http.input'] + $input;
    }

    public function recordHttpOutput(array $output): void
    {
        if (!$this->active || !$this->options->watcherEnabled('http')) {
            return;
        }
        $ignore = $this->options->watcher('http')['ignore_status'] ?? [];
        $status = (int)($output['status'] ?? 0);
        if (is_array($ignore) && $ignore !== [] && in_array($status, array_map('intval', $ignore), true)) {
            return;
        }
        $this->entries[] = ['event' => 'http.output'] + $output;
    }

    public function recordConsole(array $detail): void
    {
        if (!$this->active || !$this->options->watcherEnabled('console')) {
            return;
        }
        $this->entries[] = ['event' => 'console.result'] + $detail;
    }

    public function recordEvent(string $name, array $data = []): void
    {
        if (!$this->active) {
            return;
        }
        $bucket = self::eventBucket($name);
        if ($bucket !== null && !$this->options->watcherEnabled($bucket)) {
            return;
        }
        if ($bucket === null && !$this->options->watcherEnabled('events')) {
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
        if ($this->scope !== null) {
            foreach (array_reverse($this->registration->watchers($this->options)) as $watcher) {
                try {
                    $watcher->stop($this->scope, $this);
                } catch (\Throwable) {
                }
            }
        }
        $context = $this->context;
        $duration = round(max(0.0, microtime(true) - $this->started) * 1000, 3);
        $correlationId = $context?->tags['correlation_id'] ?? null;
        $correlationId = is_string($correlationId) ? $correlationId : null;
        $session = new MonitoringSession(
            $context?->channel ?? 'unknown',
            $context?->tags ?? [],
            $correlationId,
            $duration,
            $this->entries,
        );
        if (!$this->registration->keepSession($session)) {
            $this->entries = [];
            $this->context = null;
            return;
        }
        $tags = $session->mergedTags($this->registration->extraTags($session));
        $fields = [
            'channel' => $session->channel,
            'tags' => $tags,
            'correlation_id' => $session->correlationId,
            'duration_ms' => $session->durationMs,
            'entries' => $session->entries,
        ];
        try {
            if ($this->options->writesDatabase() && $this->store !== null) {
                $persisted = new MonitoringSession($session->channel, $tags, $session->correlationId, $session->durationMs, $session->entries);
                $database = $this->resolveDatabase();
                $this->store->persist($persisted, $database);
            }
            if ($this->options->writesDiary()) {
                $this->flushDiary($fields);
            }
        } catch (\Throwable) {
        }
        $this->entries = [];
        $this->context = null;
    }

    private function resolveDatabase(): ?Database
    {
        if ($this->scope === null || !$this->scope->has(Database::class)) {
            return null;
        }
        try {
            return $this->scope->get(Database::class);
        } catch (\Throwable) {
            return null;
        }
    }

    /** @param array<string, mixed> $fields */
    private function flushDiary(array $fields): void
    {
        if ($this->testWriter !== null) {
            $writer = $this->testWriter;
            $writer(json_encode([
                'time' => gmdate('Y-m-d\TH:i:s\Z'),
                'event' => 'monitoring.session',
            ] + $fields, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES) . "\n");
        } elseif ($this->diary !== null) {
            $this->diary->event('monitoring.session', $fields);
        }
    }

    private static function eventBucket(string $name): ?string
    {
        if (str_starts_with($name, 'queue.') || str_starts_with($name, 'job.')) {
            return 'queue';
        }
        if (str_starts_with($name, 'schedule.')) {
            return 'schedule';
        }
        if (str_starts_with($name, 'tcp.')) {
            return 'tcp';
        }
        if (str_starts_with($name, 'grpc.')) {
            return 'grpc';
        }
        return null;
    }
}
