<?php

declare(strict_types=1);

namespace Loom\Monitoring;

use Loom\Database;

/** 仪表盘查询；driver=database 时使用。 */
final class MonitoringRepository
{
    /** @var array<string, string> */
    public const ENTRY_PREFIXES = [
        'http' => 'http.',
        'console' => 'console.result',
        'sql' => 'database.sql',
        'queue' => 'queue.',
        'cache' => 'cache.',
        'schedule' => 'schedule.',
        'tcp' => 'tcp.',
        'grpc' => 'grpc.',
        'events' => 'app.event',
        'filesystem' => 'filesystem.',
    ];

    public function __construct(private readonly MonitoringOptions $options)
    {
    }

    private function connection(Database $database): Database
    {
        $name = $this->options->storage->connection;
        return ($name !== null && $name !== '') ? $database->connection($name) : $database;
    }

    /**
     * @param array{tag?: string, correlation_id?: string, path?: string, status?: string} $filters
     * @return list<array<string, mixed>>
     */
    public function recentSessions(Database $database, int $page = 1, int $perPage = 40, array $filters = []): array
    {
        $db = $this->connection($database);
        $perPage = max(1, min(100, $perPage));
        $page = max(1, $page);
        $offset = ($page - 1) * $perPage;
        $params = [];
        $where = $this->sessionFilterSql($filters, $params, 's');
        $rows = $db->query(
            'SELECT s.id, s.correlation_id, s.channel, s.tags, s.duration_ms, s.created_at
            FROM loom_monitoring_sessions s
            WHERE 1=1' . $where . '
            ORDER BY s.id DESC LIMIT ' . $perPage . ' OFFSET ' . $offset,
            $params,
        );
        return $this->decodeSessions($rows);
    }

    /**
     * @param array{tag?: string, correlation_id?: string, path?: string, status?: string} $filters
     */
    public function countSessions(Database $database, array $filters = []): int
    {
        $db = $this->connection($database);
        $params = [];
        $where = $this->sessionFilterSql($filters, $params, 's');
        $rows = $db->query('SELECT COUNT(*) AS c FROM loom_monitoring_sessions s WHERE 1=1' . $where, $params);
        return (int)($rows[0]['c'] ?? 0);
    }

    /** @return array<string, int> */
    public function entryTypeCounts(Database $database): array
    {
        $db = $this->connection($database);
        $counts = [];
        foreach (self::ENTRY_PREFIXES as $type => $prefix) {
            $like = $prefix === '' ? '%' : $prefix . '%';
            $rows = $db->query('SELECT COUNT(*) AS c FROM loom_monitoring_entries WHERE event LIKE ?', [$like]);
            $counts[$type] = (int)($rows[0]['c'] ?? 0);
        }
        return $counts;
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

    /**
     * @param array{tag?: string, correlation_id?: string, path?: string, status?: string} $filters
     * @return list<array<string, mixed>>
     */
    public function listEntries(Database $database, string $eventPrefix, int $page = 1, int $perPage = 40, array $filters = []): array
    {
        $db = $this->connection($database);
        $perPage = max(1, min(100, $perPage));
        $page = max(1, $page);
        $offset = ($page - 1) * $perPage;
        $like = $eventPrefix === '' ? '%' : $eventPrefix . '%';
        $params = [$like];
        $sessionWhere = $this->sessionFilterSql($filters, $params, 's');
        $entryStatus = $this->entryStatusFilterSql($filters, $params, 'e');
        $sql = 'SELECT e.id, e.session_id, e.event, e.payload, s.correlation_id, s.channel, s.created_at
            FROM loom_monitoring_entries e
            INNER JOIN loom_monitoring_sessions s ON s.id = e.session_id
            WHERE e.event LIKE ?' . $sessionWhere . $entryStatus . '
            ORDER BY e.id DESC LIMIT ' . $perPage . ' OFFSET ' . $offset;
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

    /**
     * @param array{tag?: string, correlation_id?: string, path?: string, status?: string} $filters
     */
    public function countEntries(Database $database, string $eventPrefix, array $filters = []): int
    {
        $db = $this->connection($database);
        $like = $eventPrefix === '' ? '%' : $eventPrefix . '%';
        $params = [$like];
        $sessionWhere = $this->sessionFilterSql($filters, $params, 's');
        $entryStatus = $this->entryStatusFilterSql($filters, $params, 'e');
        $rows = $db->query(
            'SELECT COUNT(*) AS c
            FROM loom_monitoring_entries e
            INNER JOIN loom_monitoring_sessions s ON s.id = e.session_id
            WHERE e.event LIKE ?' . $sessionWhere . $entryStatus,
            $params,
        );
        return (int)($rows[0]['c'] ?? 0);
    }

    /**
     * @param array{tag?: string, correlation_id?: string, path?: string, status?: string} $filters
     * @param list<mixed> $params
     */
    private function sessionFilterSql(array $filters, array &$params, string $alias): string
    {
        $sql = '';
        $correlation = trim((string)($filters['correlation_id'] ?? ''));
        if ($correlation !== '') {
            $sql .= ' AND ' . $alias . '.correlation_id = ?';
            $params[] = $correlation;
        }
        $path = trim((string)($filters['path'] ?? ''));
        if ($path !== '') {
            $sql .= ' AND (JSON_UNQUOTE(JSON_EXTRACT(' . $alias . '.tags, \'$.path\')) = ?
                OR EXISTS (
                    SELECT 1 FROM loom_monitoring_session_tags pt
                    WHERE pt.session_id = ' . $alias . '.id AND pt.tag_key = \'path\' AND pt.tag_value = ?
                ))';
            $params[] = $path;
            $params[] = $path;
        }
        $tag = trim((string)($filters['tag'] ?? ''));
        if ($tag !== '') {
            if (str_contains($tag, ':')) {
                [$key, $value] = explode(':', $tag, 2);
                $sql .= ' AND EXISTS (
                    SELECT 1 FROM loom_monitoring_session_tags tt
                    WHERE tt.session_id = ' . $alias . '.id AND tt.tag_key = ? AND tt.tag_value = ?
                )';
                $params[] = $key;
                $params[] = $value;
            } else {
                $sql .= ' AND EXISTS (
                    SELECT 1 FROM loom_monitoring_session_tags tt
                    WHERE tt.session_id = ' . $alias . '.id AND (tt.tag_key = ? OR tt.tag_value = ?)
                )';
                $params[] = $tag;
                $params[] = $tag;
            }
        }
        $status = trim((string)($filters['status'] ?? ''));
        if ($status !== '' && ctype_digit($status)) {
            $sql .= ' AND EXISTS (
                SELECT 1 FROM loom_monitoring_entries es
                WHERE es.session_id = ' . $alias . '.id AND es.event = \'http.output\'
                AND CAST(JSON_UNQUOTE(JSON_EXTRACT(es.payload, \'$.status\')) AS UNSIGNED) = ?
            )';
            $params[] = (int)$status;
        }
        return $sql;
    }

    /**
     * @param array{tag?: string, correlation_id?: string, path?: string, status?: string} $filters
     * @param list<mixed> $params
     */
    private function entryStatusFilterSql(array $filters, array &$params, string $alias): string
    {
        $status = trim((string)($filters['status'] ?? ''));
        if ($status === '' || !ctype_digit($status)) {
            return '';
        }
        $params[] = (int)$status;
        return ' AND (
            ' . $alias . '.event <> \'http.output\'
            OR CAST(JSON_UNQUOTE(JSON_EXTRACT(' . $alias . '.payload, \'$.status\')) AS UNSIGNED) = ?
        )';
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
