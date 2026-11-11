<?php

declare(strict_types=1);

namespace Loom;

/**
 * 关系查询基类。在默认约束下首次 get/first 的结果缓存在父模型上；追加 where、orderBy 等条件后不复用缓存。
 */
abstract class Relation
{
    private string $name = 'relation';
    protected bool $constrained = false;
    private ?ModelQuery $query = null;

    public function __construct(
        protected object $parent,
        protected string $related,
        protected Database $database,
    ) {
        $parentClass = $parent::class;
        if ($parentClass::resolveDatabase($database)->modelConnectionName() !== $related::resolveDatabase($database)->modelConnectionName()) {
            throw new \LogicException('Related model must use the same connection as the parent');
        }
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function isConstrained(): bool
    {
        return $this->constrained;
    }

    public function getQuery(): ModelQuery
    {
        return $this->baseQuery();
    }

    abstract protected function newBaseQuery(): ModelQuery;

    protected function baseQuery(): ModelQuery
    {
        if ($this->query === null) {
            $this->query = $this->newBaseQuery();
        }
        return $this->query;
    }

    protected function clearCache(): void
    {
        if ($this->parent instanceof Model) {
            $this->parent->forgetRelationCache($this->name);
        }
    }

    /**
     * 生成与父表行相关的 EXISTS 子查询（不含外层 EXISTS 关键字）。
     *
     * @return array{0: string, 1: array}
     */
    abstract public function existenceSql(string $parentTable, ?callable $constraint = null): array;

    public function get(): array
    {
        if ($this->constrained) {
            if ($this->query === null) {
                throw new \LogicException('Constrained relation has no query');
            }
            return $this->query->get();
        }
        if ($this->parent instanceof Model && $this->parent->hasRelationCache($this->name)) {
            return $this->parent->relationCache($this->name);
        }
        $records = $this->baseQuery()->get();
        if ($this->parent instanceof Model) {
            $this->parent->storeRelationCache($this->name, $records);
        }
        return $records;
    }

    public function first(): ?object
    {
        if ($this->constrained) {
            return $this->query->first();
        }
        $records = $this->get();
        return $records[0] ?? null;
    }

    public function firstOrFail(): object
    {
        $record = $this->first();
        if ($record === null) {
            throw new HttpError(404, '记录不存在');
        }
        return $record;
    }

    public function where(string $field, mixed $operator, mixed $value = null): static
    {
        $copy = $this->copyRelation();
        $copy->constrained = true;
        $copy->query = func_num_args() === 2
            ? $this->baseQuery()->where($field, $operator)
            : $this->baseQuery()->where($field, $operator, $value);
        return $copy;
    }

    public function orderBy(string $field, string $direction = 'ASC'): static
    {
        $copy = $this->copyRelation();
        $copy->constrained = true;
        $copy->query = $this->baseQuery()->orderBy($field, $direction);
        return $copy;
    }

    public function limit(int $limit): static
    {
        $copy = $this->copyRelation();
        $copy->constrained = true;
        $copy->query = $this->baseQuery()->limit($limit);
        return $copy;
    }

    public function offset(int $offset): static
    {
        $copy = $this->copyRelation();
        $copy->constrained = true;
        $copy->query = $this->baseQuery()->offset($offset);
        return $copy;
    }

    public function whereIn(string $field, array $values): static
    {
        $copy = $this->copyRelation();
        $copy->constrained = true;
        $copy->query = $this->baseQuery()->whereIn($field, $values);
        return $copy;
    }

    protected function copyRelation(): static
    {
        $copy = clone $this;
        return $copy;
    }

  /**
     * @param array $records 预加载结果；写入父模型缓存。
     */
    public function setEagerCache(array $records): void
    {
        if ($this->parent instanceof Model) {
            $this->parent->storeRelationCache($this->name, $records);
        }
    }

    protected static function snakeShort(string $class): string
    {
        $short = (new \ReflectionClass($class))->getShortName();
        return strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $short));
    }

    protected static function singleKey(string $class, ?string $key, string $role): string
    {
        if ($key !== null && $key !== '') {
            return $key;
        }
        try {
            return $class::primaryField();
        } catch (\LogicException) {
            throw new \LogicException('Composite keys require an explicit ' . $role . ' key for relations');
        }
    }

    protected function parentValue(string $field): mixed
    {
        $property = new \ReflectionProperty($this->parent, $field);
        if (!$property->isInitialized($this->parent)) {
            throw new \LogicException('Relation requires initialized parent key: ' . $field);
        }
        return $property->getValue($this->parent);
    }

    protected function relatedQuery(): ModelQuery
    {
        return $this->related::using($this->database);
    }

    protected function parentTable(): string
    {
        $table = ModuleDiscovery::attribute(new \ReflectionClass($this->parent::class), \Database\Annotation\Table::class);
        if ($table === null) {
            throw new \LogicException('Relation parent requires a Table annotation');
        }
        return $table->name;
    }

    protected function relatedTable(): string
    {
        $table = ModuleDiscovery::attribute(new \ReflectionClass($this->related), \Database\Annotation\Table::class);
        if ($table === null) {
            throw new \LogicException('Related model requires a Table annotation');
        }
        return $table->name;
    }

    protected function applyConstraint(ModelQuery $query, ?callable $constraint): ModelQuery
    {
        if ($constraint === null) {
            return $query;
        }
        $constraint($query);
        return $query;
    }
}
