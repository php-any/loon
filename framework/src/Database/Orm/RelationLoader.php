<?php

declare(strict_types=1);

namespace Loom;

/**
 * 解析 with/load 路径并在集合上批量预加载关系。
 */
final class RelationLoader
{
    /**
     * @param array<int, string|array<string, mixed>> $relations
     * @return array<int, array{path: array<int, string>, constraint: ?callable}>
     */
    public static function parse(array $relations): array
    {
        $parsed = [];
        foreach ($relations as $key => $value) {
            if (is_int($key) && is_string($value)) {
                foreach (self::splitNested($value) as $item) {
                    $parsed[] = $item;
                }
                continue;
            }
            if (is_string($key)) {
                foreach (self::splitNested($key) as $item) {
                    if ($item['path'] === explode('.', $key)) {
                        $item['constraint'] = is_callable($value) ? $value : null;
                    }
                    $parsed[] = $item;
                }
            }
        }
        return $parsed;
    }

    /**
     * @return array<int, array{path: array<int, string>, constraint: ?callable}>
     */
    private static function splitNested(string $relation): array
    {
        $parts = explode('.', $relation);
        $out = [];
        $path = [];
        foreach ($parts as $part) {
            $path[] = $part;
            $out[] = ['path' => $path, 'constraint' => null];
        }
        return $out;
    }

    /**
     * @param array<int, object> $models
     * @param array<int, array{path: array<int, string>, constraint: ?callable}> $specs
     */
    public static function eagerLoad(array $models, array $specs, Database $database): void
    {
        if ($models === []) {
            return;
        }
        $roots = [];
        foreach ($specs as $spec) {
            $name = $spec['path'][0];
            if (!isset($roots[$name])) {
                $roots[$name] = null;
            }
            if (count($spec['path']) === 1 && $spec['constraint'] !== null) {
                $roots[$name] = $spec['constraint'];
            }
        }
        foreach ($roots as $name => $constraint) {
            self::loadRootRelation($models, $name, $constraint, $database);
        }
        foreach ($specs as $spec) {
            if (count($spec['path']) < 2) {
                continue;
            }
            $nested = implode('.', array_slice($spec['path'], 1));
            $childModels = [];
            foreach ($models as $model) {
                if ($model instanceof Model) {
                    foreach ($model->relationCache($spec['path'][0]) as $child) {
                        $childModels[] = $child;
                    }
                }
            }
            if ($childModels !== []) {
                self::eagerLoad($childModels, [['path' => explode('.', $nested), 'constraint' => $spec['constraint']]], $database);
            }
        }
    }

    /**
     * @param array<int, object> $models
     */
    private static function loadRootRelation(array $models, string $name, ?callable $constraint, Database $database): void
    {
        $relation = self::relationInstance($models[0], $name, $database);
        if ($relation instanceof BelongsTo) {
            self::eagerBelongsTo($models, $name, $relation, $constraint);
            return;
        }
        if ($relation instanceof HasOne) {
            self::eagerHasOne($models, $name, $relation, $constraint);
            return;
        }
        if ($relation instanceof HasMany) {
            self::eagerHasMany($models, $name, $relation, $constraint);
            return;
        }
        if ($relation instanceof BelongsToMany) {
            self::eagerBelongsToMany($models, $name, $relation, $constraint);
        }
    }

    public static function relationInstance(object $model, string $name, Database $database): Relation
    {
        if (!method_exists($model, $name)) {
            throw new \LogicException('Unknown relation: ' . $name);
        }
        if ($model instanceof Model) {
            $model->setRelationDatabase($database);
        }
        $relation = $model->{$name}();
        if (!($relation instanceof Relation)) {
            throw new \LogicException('Relation method must return a Relation instance: ' . $name);
        }
        $relation->setName($name);
        return $relation;
    }

    /**
     * @param array<int, object> $models
     */
    private static function eagerBelongsTo(array $models, string $name, BelongsTo $relation, ?callable $constraint): void
    {
        $foreignKey = $relation->foreignKey();
        $ownerKey = $relation->ownerKey();
        $ids = [];
        foreach ($models as $model) {
            $property = new \ReflectionProperty($model, $foreignKey);
            if ($property->isInitialized($model)) {
                $ids[] = $property->getValue($model);
            }
        }
        $ids = array_values(array_unique($ids));
        $query = $relation->getQuery()->relationFreshQuery();
        if ($constraint !== null) {
            $constraint($query);
        }
        $related = $ids === [] ? [] : $query->whereIn($ownerKey, $ids)->get();
        $map = [];
        foreach ($related as $row) {
            $map[(new \ReflectionProperty($row, $ownerKey))->getValue($row)] = $row;
        }
        foreach ($models as $model) {
            if (!($model instanceof Model)) {
                continue;
            }
            $property = new \ReflectionProperty($model, $foreignKey);
            $value = $property->isInitialized($model) ? $property->getValue($model) : null;
            $model->storeRelationCache($name, isset($map[$value]) ? [$map[$value]] : []);
        }
    }

    /**
     * @param array<int, object> $models
     */
    private static function eagerHasOne(array $models, string $name, HasOne $relation, ?callable $constraint): void
    {
        $foreignKey = $relation->foreignKey();
        $localKey = $relation->localKey();
        $parentIds = self::parentKeys($models, $localKey);
        $query = $relation->getQuery()->relationFreshQuery();
        if ($constraint !== null) {
            $constraint($query);
        }
        $related = $parentIds === [] ? [] : $query->whereIn($foreignKey, $parentIds)->get();
        $map = [];
        foreach ($related as $row) {
            $property = new \ReflectionProperty($row, $foreignKey);
            $map[$property->getValue($row)] = [$row];
        }
        foreach ($models as $model) {
            if (!($model instanceof Model)) {
                continue;
            }
            $id = (new \ReflectionProperty($model, $localKey))->getValue($model);
            $model->storeRelationCache($name, $map[$id] ?? []);
        }
    }

    /**
     * @param array<int, object> $models
     */
    private static function eagerHasMany(array $models, string $name, HasMany $relation, ?callable $constraint): void
    {
        $foreignKey = $relation->foreignKey();
        $localKey = $relation->localKey();
        $parentIds = self::parentKeys($models, $localKey);
        $query = $relation->getQuery()->relationFreshQuery();
        if ($constraint !== null) {
            $constraint($query);
        }
        $related = $parentIds === [] ? [] : $query->whereIn($foreignKey, $parentIds)->get();
        $map = [];
        foreach ($related as $row) {
            $property = new \ReflectionProperty($row, $foreignKey);
            $fk = $property->getValue($row);
            $map[$fk] ??= [];
            $map[$fk][] = $row;
        }
        foreach ($models as $model) {
            if (!($model instanceof Model)) {
                continue;
            }
            $id = (new \ReflectionProperty($model, $localKey))->getValue($model);
            $model->storeRelationCache($name, $map[$id] ?? []);
        }
    }

    /**
     * @param array<int, object> $models
     */
    private static function eagerBelongsToMany(array $models, string $name, BelongsToMany $relation, ?callable $constraint): void
    {
        $localKey = $relation->localKey();
        $parentIds = self::parentKeys($models, $localKey);
        $pivotTable = $relation->pivotTable();
        $foreignPivotKey = $relation->foreignPivotKey();
        $relatedPivotKey = $relation->relatedPivotKey();
        $pivotRows = self::pivotRows($relation, $pivotTable, $foreignPivotKey, $relatedPivotKey, $parentIds);
        $allRelated = [];
        foreach ($pivotRows as $rows) {
            foreach ($rows as $relatedId) {
                $allRelated[] = $relatedId;
            }
        }
        $allRelated = array_values(array_unique($allRelated));
        $relatedClass = $relation->relatedClass();
        $pk = $relatedClass::primaryField();
        $query = $relatedClass::using($relation->getQuery()->relationDatabase());
        if ($constraint !== null) {
            $constraint($query);
        }
        $relatedModels = $allRelated === [] ? [] : $query->whereIn($pk, $allRelated)->get();
        $relatedMap = [];
        foreach ($relatedModels as $row) {
            $relatedMap[(new \ReflectionProperty($row, $pk))->getValue($row)] = $row;
        }
        foreach ($models as $model) {
            if (!($model instanceof Model)) {
                continue;
            }
            $id = (new \ReflectionProperty($model, $localKey))->getValue($model);
            $bucket = [];
            foreach ($pivotRows[$id] ?? [] as $relatedId) {
                if (isset($relatedMap[$relatedId])) {
                    $bucket[] = $relatedMap[$relatedId];
                }
            }
            $model->storeRelationCache($name, $bucket);
        }
    }

    /**
     * @param array<int, mixed> $parentIds
     * @return array<int|string, array<int, mixed>>
     */
    private static function pivotRows(BelongsToMany $relation, string $pivotTable, string $foreignPivotKey, string $relatedPivotKey, array $parentIds): array
    {
        if ($parentIds === []) {
            return [];
        }
        $pivot = SqlIdentifier::quote($pivotTable);
        $foreign = SqlIdentifier::quote($foreignPivotKey);
        $related = SqlIdentifier::quote($relatedPivotKey);
        $marks = implode(',', array_fill(0, count($parentIds), '?'));
        $sql = 'SELECT ' . $foreign . ',' . $related . ' FROM ' . $pivot . ' WHERE ' . $foreign . ' IN (' . $marks . ')';
        $database = $relation->getQuery()->relationDatabase();
        $tx = $database->activeTransaction();
        $map = [];
        foreach ($database->runner($tx)->query($sql, $parentIds) as $row) {
            $vars = get_object_vars($row);
            $parentId = $vars[$foreignPivotKey];
            $map[$parentId] ??= [];
            $map[$parentId][] = $vars[$relatedPivotKey];
        }
        return $map;
    }

    /**
     * @param array<int, object> $models
     * @return array<int, mixed>
     */
    private static function parentKeys(array $models, string $localKey): array
    {
        $ids = [];
        foreach ($models as $model) {
            $property = new \ReflectionProperty($model, $localKey);
            if ($property->isInitialized($model)) {
                $ids[] = $property->getValue($model);
            }
        }
        return array_values(array_unique($ids));
    }
}
