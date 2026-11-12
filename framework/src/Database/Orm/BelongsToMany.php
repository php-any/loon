<?php

declare(strict_types=1);

namespace Loom;

final class BelongsToMany extends Relation
{
    public function __construct(
        object $parent,
        string $related,
        Database $database,
        private string $pivotTable,
        private ?string $foreignPivotKey = null,
        private ?string $relatedPivotKey = null,
        private ?string $localKey = null,
    ) {
        SqlIdentifier::quote($this->pivotTable);
        $this->foreignPivotKey = $foreignPivotKey ?? self::snakeShort($parent::class) . '_id';
        $this->relatedPivotKey = $relatedPivotKey ?? self::snakeShort($related) . '_id';
        $this->localKey = self::singleKey($parent::class, $this->localKey, 'local');
        parent::__construct($parent, $related, $database);
    }

    public function pivotTable(): string
    {
        return $this->pivotTable;
    }

    public function foreignPivotKey(): string
    {
        return $this->foreignPivotKey;
    }

    public function relatedPivotKey(): string
    {
        return $this->relatedPivotKey;
    }

    public function localKey(): string
    {
        return $this->localKey;
    }

    public function relatedClass(): string
    {
        return $this->related;
    }

    protected function newBaseQuery(): ModelQuery
    {
        $parentId = $this->parentValue($this->localKey);
        $ids = $this->pivotRelatedIds($parentId);
        return $this->relatedQuery()->whereIn($this->related::primaryField(), $ids);
    }

    /**
     * @return array<int, mixed>
     */
    private function pivotRelatedIds(mixed $parentId): array
    {
        $pivot = SqlIdentifier::quote($this->pivotTable);
        $foreign = SqlIdentifier::quote($this->foreignPivotKey);
        $related = SqlIdentifier::quote($this->relatedPivotKey);
        $sql = 'SELECT ' . $related . ' FROM ' . $pivot . ' WHERE ' . $foreign . '=?';
        $ids = [];
        $tx = $this->database->activeTransaction();
        foreach ($this->database->runner($tx)->query($sql, [$parentId]) as $row) {
            $vars = get_object_vars($row);
            $ids[] = $vars[$this->relatedPivotKey];
        }
        return $ids;
    }

    public function existenceSql(string $parentTable, ?callable $constraint = null): array
    {
        $relatedTable = $this->relatedTable();
        $pivot = SqlIdentifier::quote($this->pivotTable);
        $parentQuoted = SqlIdentifier::quote($parentTable);
        $relatedQuoted = SqlIdentifier::quote($relatedTable);
        $fkPivot = SqlIdentifier::quote($this->foreignPivotKey);
        $relPivot = SqlIdentifier::quote($this->relatedPivotKey);
        $ownerColumn = SqlIdentifier::quote($this->related::modelColumn($this->related::primaryField()));
        $localColumn = SqlIdentifier::quote($this->parent::modelColumn($this->localKey));
        $base = 'SELECT 1 FROM ' . $pivot . ' INNER JOIN ' . $relatedQuoted . ' ON '
            . $relatedQuoted . '.' . $ownerColumn . '=' . $pivot . '.' . $relPivot
            . ' WHERE ' . $pivot . '.' . $fkPivot . '=' . $parentQuoted . '.' . $localColumn;
        $probe = $this->relatedQuery()->relationFreshQuery();
        $probe = $this->applyConstraint($probe, $constraint);
        [$extra, $args] = $probe->exportWhere();
        if ($extra !== '') {
            $prefix = SqlIdentifier::quote($relatedTable) . '.';
            $extra = preg_replace('/`([A-Za-z_][A-Za-z0-9_]*)`/', $prefix . '`$1`', $extra) ?? $extra;
            $base .= ' AND ' . $extra;
        }
        return [$base, $args];
    }

    /**
     * @param int|array<int, mixed> $ids
     */
    public function attach(int|array $ids): void
    {
        foreach ($this->normalizeIds($ids) as $id) {
            $this->insertPivot($id);
        }
        $this->clearCache();
    }

    /**
     * @param int|array<int, mixed>|null $ids null 表示移除全部关联。
     */
    public function detach(int|array|null $ids = null): void
    {
        $parentId = $this->parentValue($this->localKey);
        $pivot = SqlIdentifier::quote($this->pivotTable);
        $foreign = SqlIdentifier::quote($this->foreignPivotKey);
        $related = SqlIdentifier::quote($this->relatedPivotKey);
        if ($ids === null) {
            $sql = 'DELETE FROM ' . $pivot . ' WHERE ' . $foreign . '=?';
            $args = [$parentId];
        } else {
            $list = $this->normalizeIds($ids);
            if ($list === []) {
                return;
            }
            $marks = implode(',', array_fill(0, count($list), '?'));
            $sql = 'DELETE FROM ' . $pivot . ' WHERE ' . $foreign . '=? AND ' . $related . ' IN (' . $marks . ')';
            $args = array_merge([$parentId], $list);
        }
        $tx = $this->database->activeTransaction();
        $this->database->runner($tx)->execute($sql, $args);
        $this->clearCache();
    }

    /**
     * @param array<int, mixed> $ids
     */
    public function sync(array $ids): void
    {
        $parentId = $this->parentValue($this->localKey);
        $current = $this->pivotRelatedIds($parentId);
        $normalized = [];
        foreach ($ids as $id) {
            $normalized[] = (int)$id;
        }
        $ids = array_values(array_unique($normalized));
        $detach = array_diff($current, $ids);
        $attach = array_diff($ids, $current);
        if ($detach !== []) {
            $this->detach($detach);
        }
        if ($attach !== []) {
            $this->attach($attach);
        }
        $this->clearCache();
    }

    private function insertPivot(int $relatedId): void
    {
        $parentId = $this->parentValue($this->localKey);
        $pivot = SqlIdentifier::quote($this->pivotTable);
        $foreign = SqlIdentifier::quote($this->foreignPivotKey);
        $related = SqlIdentifier::quote($this->relatedPivotKey);
        $sql = 'INSERT INTO ' . $pivot . '(' . $foreign . ',' . $related . ') VALUES (?,?)';
        $tx = $this->database->activeTransaction();
        $this->database->runner($tx)->execute($sql, [$parentId, $relatedId]);
    }

    /**
     * @return array<int, int>
     */
    private function normalizeIds(int|array $ids): array
    {
        $list = is_array($ids) ? $ids : [$ids];
        $normalized = [];
        foreach ($list as $id) {
            $normalized[] = (int)$id;
        }
        return array_values(array_unique($normalized));
    }
}
