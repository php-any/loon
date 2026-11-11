<?php

declare(strict_types=1);

namespace Loom;

final class BelongsTo extends Relation
{
    public function __construct(
        object $parent,
        string $related,
        Database $database,
        private ?string $foreignKey = null,
        private ?string $ownerKey = null,
    ) {
        $this->foreignKey = $foreignKey ?? self::snakeShort($related) . '_id';
        $parent::class::modelColumn($this->foreignKey);
        $this->ownerKey = self::singleKey($related, $this->ownerKey, 'owner');
        parent::__construct($parent, $related, $database);
    }

    public function foreignKey(): string
    {
        return $this->foreignKey;
    }

    public function ownerKey(): string
    {
        return $this->ownerKey;
    }

    protected function newBaseQuery(): ModelQuery
    {
        return $this->relatedQuery()->where($this->ownerKey, $this->parentValue($this->foreignKey));
    }

    public function existenceSql(string $parentTable, ?callable $constraint = null): array
    {
        $relatedTable = $this->relatedTable();
        $fkColumn = SqlIdentifier::quote($this->parent::class::modelColumn($this->foreignKey));
        $ownerColumn = SqlIdentifier::quote($this->related::modelColumn($this->ownerKey));
        $parentQuoted = SqlIdentifier::quote($parentTable);
        $relatedQuoted = SqlIdentifier::quote($relatedTable);
        $base = 'SELECT 1 FROM ' . $relatedQuoted . ' WHERE ' . $relatedQuoted . '.' . $ownerColumn
            . ' = ' . $parentQuoted . '.' . $fkColumn;
        $probe = $this->relatedQuery()->relationFreshQuery();
        $probe = $this->applyConstraint($probe, $constraint);
        [$extra, $args] = $probe->exportWhere();
        if ($extra !== '') {
            $base .= ' AND ' . $extra;
        }
        return [$base, $args];
    }
}
