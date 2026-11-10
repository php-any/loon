<?php

declare(strict_types=1);

namespace Loom\Monitoring;

use Loom\Database;
use Loom\Monitoring\MonitoringOptions;

/** 仪表盘查询；driver=database 时使用。 */
final class MonitoringRepository
{
    public function __construct(private readonly MonitoringOptions $options)
    {
    }

    private function connection(Database $database): Database
    {
        $name = $this->options->storage->connection;
        return ($name !== null && $name !== '') ? $database->connection($name) : $database;
    }

    /** @return list<array<string, mixed>> */
    public function recentSessions(Database $database, int $limit = 50): array
    {
        $db = $this->connection($database);
        $limit = max(1, min(200, $limit));
        $rows = $db->query(
            'SELECT id, correlation_id, channel, tags, duration_ms, created_at FROM loom_monitoring_sessions ORDER BY id DESC LIMIT ' . $limit,
        );
        return $this->decodeSessions($rows);
    }

    /** @return array<string, mixed>|null */
    public function session(Database $database, int $id): ?array
    {
        $db = $this->connection($database);
        $rows = $db->query('SELECT id, correlation_id, channel, tags, duration_ms, created_at FROM loom_monitoring_sessions WHERE id = ?', [$id]);
        if ($rows === []) {
            return null;
        }
        $session = $this->decodeSessions($rows)[0];
        $entries = $db->query('SELECT id, event, payload FROM loom_monitoring_entries WHERE session_id = ? ORDER BY id ASC', [$id]);
        foreach ($entries as &$row) {
            $payload = $row['payload'] ?? '';
            if (is_string($payload) && $payload !== '') {
                $decoded = json_decode($payload, true);
                $row['payload'] = is_array($decoded) ? $decoded : [];
            } else {
                $row['payload'] = [];
            }
        }
        unset($row);
        $session['entries'] = $entries;
        return $session;
    }

    /** @return list<array<string, mixed>> */
    public function sessionsByCorrelation(Database $database, string $correlationId): array
    {
        $db = $this->connection($database);
        $rows = $db->query(
            'SELECT id, correlation_id, channel, tags, duration_ms, created_at FROM loom_monitoring_sessions WHERE correlation_id = ? ORDER BY id ASC',
            [$correlationId],
        );
        return $this->decodeSessions($rows);
    }

    /** @return list<array<string, mixed>> */
    public function listEntries(Database $database, string $eventPrefix, int $limit = 100, ?string $correlationId = null): array
    {
        $db = $this->connection($database);
        $limit = max(1, min(500, $limit));
        $sql = 'SELECT e.id, e.session_id, e.event, e.payload, s.correlation_id, s.channel, s.created_at
            FROM loom_monitoring_entries e
            INNER JOIN loom_monitoring_sessions s ON s.id = e.session_id
            WHERE e.event LIKE ?';
        $params = [$eventPrefix . '%'];
        if ($correlationId !== null && $correlationId !== '') {
            $sql .= ' AND s.correlation_id = ?';
            $params[] = $correlationId;
        }
        $sql .= ' ORDER BY e.id DESC LIMIT ' . $limit;
        $rows = $db->query($sql, $params);
        foreach ($rows as &$row) {
            $payload = $row['payload'] ?? '';
            if (is_string($payload) && $payload !== '') {
                $decoded = json_decode($payload, true);
                $row['payload'] = is_array($decoded) ? $decoded : [];
            }
        }
        unset($row);
        return $rows;
    }

    /** @param list<array<string, mixed>> $rows */
    private function decodeSessions(array $rows): array
    {
        foreach ($rows as &$row) {
            $tags = $row['tags'] ?? '';
            if (is_string($tags) && $tags !== '') {
                $decoded = json_decode($tags, true);
                $row['tags'] = is_array($decoded) ? $decoded : [];
            } else {
                $row['tags'] = [];
            }
        }
        unset($row);
        return $rows;
    }
}
