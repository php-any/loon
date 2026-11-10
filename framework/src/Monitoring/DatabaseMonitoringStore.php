<?php

declare(strict_types=1);

namespace Loom\Monitoring;

use Loom\Database;

final class DatabaseMonitoringStore implements MonitoringStore
{
    public function __construct(private readonly MonitoringOptions $options)
    {
    }

    public function persist(MonitoringSession $session, ?Database $database): void
    {
        $db = $this->connection($database);
        if ($db === null) {
            return;
        }
        $now = (int)(microtime(true) * 1000);
        $tagsJson = json_encode($session->tags, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
        $inserted = $db->execute(
            'INSERT INTO loom_monitoring_sessions (correlation_id, channel, tags, duration_ms, created_at) VALUES (?, ?, ?, ?, ?)',
            [$session->correlationId, $session->channel, $tagsJson, $session->durationMs, $now],
        );
        $sessionId = (int)($inserted['id'] ?? 0);
        if ($sessionId <= 0) {
            return;
        }
        foreach ($session->tags as $key => $value) {
            if ($value === null || is_array($value)) {
                continue;
            }
            $db->execute(
                'INSERT INTO loom_monitoring_session_tags (session_id, tag_key, tag_value) VALUES (?, ?, ?)',
                [$sessionId, (string)$key, substr((string)$value, 0, 255)],
            );
        }
        foreach ($session->entries as $entry) {
            $event = (string)($entry['event'] ?? 'unknown');
            $payload = json_encode($entry, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
            $db->execute(
                'INSERT INTO loom_monitoring_entries (session_id, event, payload) VALUES (?, ?, ?)',
                [$sessionId, substr($event, 0, 64), $payload],
            );
        }
    }

    public function prune(Database $database, int $retainHours): int
    {
        $db = $this->connection($database);
        if ($db === null) {
            return 0;
        }
        $retainHours = max(1, $retainHours);
        $cutoff = (int)(microtime(true) * 1000) - $retainHours * 3600 * 1000;
        $db->execute('DELETE FROM loom_monitoring_session_tags WHERE session_id IN (SELECT id FROM loom_monitoring_sessions WHERE created_at < ?)', [$cutoff]);
        $db->execute('DELETE FROM loom_monitoring_entries WHERE session_id IN (SELECT id FROM loom_monitoring_sessions WHERE created_at < ?)', [$cutoff]);
        $db->execute('DELETE FROM loom_monitoring_sessions WHERE created_at < ?', [$cutoff]);
        return 1;
    }

    private function connection(?Database $database): ?Database
    {
        if ($database === null) {
            return null;
        }
        $name = $this->options->storage->connection;
        return ($name !== null && $name !== '') ? $database->connection($name) : $database;
    }
}
