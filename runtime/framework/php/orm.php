<?php
declare(strict_types=1);
namespace Loom;

abstract class Model
{
    protected static string $table = '';
    protected static array $columns = [];
    protected static array $fillable = [];
    protected static array $casts = [];
    private array $attributes = [];
    private array $dirty = [];
    public static function query(): Query { return new Query(static::class, static::$table, static::$columns); }
    public static function hydrate(array $attributes): static
    {
        $model = new static();
        foreach ($attributes as $key => $value) { $model->attributes[$key] = static::cast($key, $value); }
        return $model;
    }
    private static function cast(string $key, mixed $value): mixed
    {
        if ($value === null) { return null; }
        $cast = static::$casts[$key] ?? '';
        if ($cast === 'int') { return (int) $value; }
        if ($cast === 'bool') { return (bool) $value; }
        if ($cast === 'string') { return (string) $value; }
        return $value;
    }
    public static function writable(array $values): array
    {
        if ($values === []) { throw new \LogicException('Empty model attributes'); }
        foreach ($values as $key => $value) {
            if (!in_array($key, static::$fillable, true)) { throw new \LogicException('Attribute is not fillable: ' . $key); }
            $values[$key] = static::cast($key, $value);
            if (is_bool($values[$key])) { $values[$key] = $values[$key] ? 1 : 0; }
        }
        return $values;
    }
    public static function create(array $values): static
    {
        $values = static::writable($values); $columns = []; $marks = []; $args = [];
        foreach ($values as $key => $value) { $columns[] = Query::identifier($key); $marks[] = '?'; $args[] = $value; }
        $result = Database::execute('INSERT INTO ' . Query::identifier(static::$table) . ' (' . implode(',', $columns) . ') VALUES (' . implode(',', $marks) . ')', $args);
        return static::query()->where('id', '=', $result['id'])->firstOrFail();
    }
    public function get(string $key): mixed
    {
        if (!array_key_exists($key, $this->attributes)) { throw new \LogicException('Unknown attribute: ' . $key); }
        return $this->attributes[$key];
    }
    public function set(string $key, mixed $value): static
    {
        static::writable([$key => $value]); $value = static::cast($key, $value);
        if (!array_key_exists($key, $this->attributes) || $this->attributes[$key] !== $value) { $this->dirty[$key] = $value; $this->attributes[$key] = $value; }
        return $this;
    }
    public function save(): void
    {
        if ($this->dirty === []) { return; }
        $n = static::query()->where('id', '=', $this->get('id'))->update($this->dirty);
        if ($n !== 1) { throw new HttpError(404, '记录已不存在'); }
        $this->dirty = [];
    }
    public function delete(): void { static::query()->where('id', '=', $this->get('id'))->delete(); }
    public function toArray(): array { return $this->attributes; }
}

final class Query
{
    private string $model;
    private string $table;
    private array $columns;
    private array $predicates = [];
    private array $args = [];
    private string $order = '';
    private int $maximum = 1000;
    public function __construct(string $model, string $table, array $columns) { self::identifier($table); $this->model = $model; $this->table = $table; $this->columns = $columns; }
    public static function identifier(string $value): string
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $value)) { throw new \LogicException('Invalid SQL identifier'); }
        return '"' . $value . '"';
    }
    private function column(string $key): string
    {
        if (!in_array($key, $this->columns, true)) { throw new \LogicException('Unknown model column: ' . $key); }
        return self::identifier($key);
    }
    public function where(string $column, string $operator, mixed $value): self
    {
        $column = $this->column($column);
        if (!in_array($operator, ['=', '!=', '<', '<=', '>', '>=', 'LIKE'], true)) { throw new \LogicException('Invalid query operator'); }
        $copy = clone $this;
        if ($value === null) {
            if ($operator !== '=' && $operator !== '!=') { throw new \LogicException('NULL supports only equality predicates'); }
            $copy->predicates[] = $column . ($operator === '=' ? ' IS NULL' : ' IS NOT NULL');
        } else { $copy->predicates[] = $column . ' ' . $operator . ' ?'; $copy->args[] = is_bool($value) ? ($value ? 1 : 0) : $value; }
        return $copy;
    }
    public function orderBy(string $column, string $direction = 'ASC'): self
    {
        $direction = strtoupper($direction); if (!in_array($direction, ['ASC', 'DESC'], true)) { throw new \LogicException('Invalid order direction'); }
        $copy = clone $this; $copy->order = ' ORDER BY ' . $this->column($column) . ' ' . $direction; return $copy;
    }
    public function limit(int $limit): self { if ($limit < 1 || $limit > 1000) { throw new \LogicException('Query limit must be 1–1000'); } $copy = clone $this; $copy->maximum = $limit; return $copy; }
    private function condition(): string { return $this->predicates === [] ? '' : ' WHERE ' . implode(' AND ', $this->predicates); }
    public function get(): array
    {
        $rows = Database::query('SELECT * FROM ' . self::identifier($this->table) . $this->condition() . $this->order . ' LIMIT ' . $this->maximum, $this->args);
        $models = []; $class = $this->model;
        foreach ($rows as $row) { $models[] = $class::hydrate($row); }
        return $models;
    }
    public function rows(): array { $rows = []; foreach ($this->get() as $model) { $rows[] = $model->toArray(); } return $rows; }
    public function first(): ?Model { $rows = $this->limit(1)->get(); return $rows[0] ?? null; }
    public function firstOrFail(): Model { $model = $this->first(); if ($model === null) { throw new HttpError(404, '记录不存在'); } return $model; }
    public function count(): int { $rows = Database::query('SELECT COUNT(*) AS aggregate FROM ' . self::identifier($this->table) . $this->condition(), $this->args); return (int) $rows[0]['aggregate']; }
    private function assertWrite(): void { if ($this->predicates === [] || $this->order !== '' || $this->maximum !== 1000) { throw new \LogicException('Writes require a predicate and no order/limit'); } }
    public function update(array $values): int
    {
        $this->assertWrite(); $class = $this->model; $values = $class::writable($values); $sets = []; $args = [];
        foreach ($values as $key => $value) { $sets[] = $this->column($key) . '=?'; $args[] = $value; }
        foreach ($this->args as $value) { $args[] = $value; }
        return Database::execute('UPDATE ' . self::identifier($this->table) . ' SET ' . implode(',', $sets) . $this->condition(), $args)['affected'];
    }
    public function delete(): int { $this->assertWrite(); return Database::execute('DELETE FROM ' . self::identifier($this->table) . $this->condition(), $this->args)['affected']; }
}
