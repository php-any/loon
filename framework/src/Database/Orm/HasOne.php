<?php

declare(strict_types=1);

namespace Loom;

final class HasOne extends Relation
{
    public function __construct(
        object $parent,
        string $related,
        Database $database,
        private ?string $foreignKey = null,
        private ?string $localKey = null,
    ) {
        $this->foreignKey = $foreignKey ?? self::snakeShort($parent::class) . '_id';
        $related::modelColumn($this->foreignKey);
        $this->localKey = self::singleKey($parent::class, $this->localKey, 'local');
        parent::__construct($parent, $related, $database);
    }

    public function foreignKey(): string
    {
        return $this->foreignKey;
    }

    public function localKey(): string
    {
        return $this->localKey;
    }

    protected function newBaseQuery(): ModelQuery
    {
        return $this->relatedQuery()->where($this->foreignKey, $this->parentValue($this->localKey));
    }

    public function existenceSql(string $parentTable, ?callable $constraint = null): array
    {
        $relatedTable = $this->relatedTable();
        $fkColumn = SqlIdentifier::quote($this->related::modelColumn($this->foreignKey));
        $localColumn = SqlIdentifier::quote($this->parent::class::modelColumn($this->localKey));
        $parentQuoted = SqlIdentifier::quote($parentTable);
        $relatedQuoted = SqlIdentifier::quote($relatedTable);
        $base = 'SELECT 1 FROM ' . $relatedQuoted . ' WHERE ' . $relatedQuoted . '.' . $fkColumn
            . ' = ' . $parentQuoted . '.' . $localColumn;
        $probe = $this->relatedQuery()->relationFreshQuery();
        $probe = $this->applyConstraint($probe, $constraint);
        [$extra, $args] = $probe->exportWhere();
        if ($extra !== '') {
            $base .= ' AND ' . $extra;
        }
        return [$base, $args];
    }

    /**
     * 在关联表插入一行并填入外键。
     */
    public function create(array $attributes): object
    {
        $attributes[$this->foreignKey] = $this->parentValue($this->localKey);
        return $this->relatedQuery()->create($attributes);
    }
}
