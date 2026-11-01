<?php

declare(strict_types=1);

namespace Loom;

/**
 * 不可变模型查询链，组合参数化条件并委托原生 SQL 执行；批量更新删除要求显式条件且不能带排序或投影限制。
 *
 * 构造参数：
 * - $model（string）：可实例化的模型完整类名，必须具有 Table 映射。
 * - $native（\Database\DB）：已绑定模型和连接的原生 Database\DB。
 * - $connection（string）：已经注册的原生连接名称。
 * - $database（?Database）：显式数据库服务，保证 ORM 与原始 SQL 使用同一事务；查询构造时可为 null。默认值为 null。
 *
 * 返回值：使用 new 构造时得到 ModelQuery 实例；注册到容器时由容器管理实例的创建与复用。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $query = \Loom\Models\User::using($database)->where('id', 1);
 */
final class ModelQuery
{
    private array $conditions = [];
    private array $arguments = [];
    private array $orders = [];
    private bool $restricted = false;
    /**
     * 初始化 ModelQuery 实例及其依赖。不可变模型查询链，组合参数化条件并委托原生 SQL 执行；批量更新删除要求显式条件且不能带排序或投影限制。
     *
     * @param string $model 可实例化的模型完整类名，必须具有 Table 映射。
     * @param \Database\DB $native 已绑定模型和连接的原生 Database\DB。
     * @param string $connection 已经注册的原生连接名称。
     * @param ?Database $database 显式数据库服务，保证 ORM 与原始 SQL 使用同一事务；查询构造时可为 null。默认值为 null。
     * @return void 无直接返回值；通过 new 表达式取得初始化后的实例。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $query = \Loom\Models\User::using($database)->where('id', 1);
     */
    public function __construct(private string $model, private \Database\DB $native, private string $connection, private ?Database $database = null)
    {
    }
    /**
     * 确认原生连接仍已注册，防止作用域清理后复用查询器。
     *
     * 参数：无。
     *
     * @return void 无返回值；连接关闭时抛出 LogicException。
     *
     * 内部调用示例（仅类内使用）：
     * @example $this->ensureConnection();
     */
    private function ensureConnection(): void
    {
        if (\Database\getConnection($this->connection) === null) {
            throw new \LogicException('Model connection has been closed');
        }
    }
    /**
     * 验证连接并把累计 AND 条件和绑定值应用到原生构建器。
     *
     * 参数：无。
     *
     * @return \Database\DB 带有当前条件的原生 DB 查询器。
     *
     * 内部调用示例（仅类内使用）：
     * @example $native = $this->builder();
     */
    private function builder(): \Database\DB
    {
        $this->ensureConnection();
        return $this->conditions === [] ? $this->native : $this->native->where(implode(' AND ', $this->conditions), ...$this->arguments);
    }
    /**
     * 添加映射字段的比较条件；双参数调用表示等值，null 仅允许等于或不等于。
     *
     * @param string $field 已声明的模型属性名或列名，不接受任意 SQL 表达式。
     * @param mixed $operator 三参数形式为比较运算符：=、!=、<>、<、<=、>、>=、LIKE；双参数形式为等值比较的值。
     * @param mixed $value 比较值，布尔值转为 0/1，null 仅用于相等或不等谓词。默认值为 null。
     * @return self 带新条件的 ModelQuery；不修改原查询。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $query = $query->where('id', '>=', 1);
     */
    public function where(string $field, mixed $operator, mixed $value = null): self
    {
        if (func_num_args() === 2) {
            $value = $operator;
            $operator = '=';
        }
        $class = $this->model;
        $column = SqlIdentifier::quote($class::modelColumn($field));
        if (!in_array($operator, ['=', '!=', '<>', '<', '<=', '>', '>=', 'LIKE'], true)) {
            throw new \InvalidArgumentException('Invalid model query operator');
        }
        $copy = clone $this;
        if ($value === null) {
            if (!in_array($operator, ['=', '!=', '<>'], true)) {
                throw new \InvalidArgumentException('NULL supports only equality predicates');
            }
            $copy->conditions[] = $column . ($operator === '=' ? ' IS NULL' : ' IS NOT NULL');
        } else {
            $copy->conditions[] = $column . ' ' . $operator . ' ?';
            $copy->arguments[] = is_bool($value) ? (int)$value : $value;
        }
        return $copy;
    }
    /**
     * 把 whereXxx 动态调用解析为模型字段并添加等值条件。
     *
     * @param string $method 动态方法名称，例如 whereEmail。
     * @param array $arguments 动态方法的位置参数列表，whereXxx 要求恰好一个值。
     * @return mixed 带新条件的 ModelQuery 副本。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $query = $query->whereEmail('user@example.com');
     */
    public function __call(string $method, array $arguments): mixed
    {
        $class = $this->model;
        return $this->where($class::dynamicField($method, $arguments), $arguments[0]);
    }
    /**
     * 添加映射字段排序，支持独立方向或“字段 ASC/DESC”形式；只允许 ASC、DESC。
     *
     * @param string $field 已声明的模型属性名或列名，不接受任意 SQL 表达式。
     * @param string $direction 排序方向 ASC 或 DESC，不区分大小写。默认值为 'ASC'。
     * @return self 累计排序后的查询副本。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $query = $query->orderBy('id', 'DESC');
     */
    public function orderBy(string $field, string $direction = 'ASC'): self
    {
        // 保留现有调用方使用的原生单字符串写法。
        if (preg_match('/^([A-Za-z_][A-Za-z0-9_]*) (ASC|DESC)$/iD', $field, $match)) {
            $field = $match[1];
            $direction = $match[2];
        }
        $class = $this->model;
        $column = SqlIdentifier::quote($class::modelColumn($field));
        $direction = strtoupper($direction);
        if (!in_array($direction, ['ASC', 'DESC'], true)) {
            throw new \InvalidArgumentException('Invalid order direction');
        }
        $copy = clone $this;
        $copy->orders[] = $column . ' ' . $direction;
        $copy->native = $this->native->orderBy(implode(', ', $copy->orders));
        $copy->restricted = true;
        return $copy;
    }
    /**
     * 设置正整数结果数量上限，并标记为不支持批量写入的查询。
     *
     * @param int $limit 结果条数上限，必须大于 0。
     * @return self 带数量限制的副本。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $query = $query->limit(25);
     */
    public function limit(int $limit): self
    {
        if ($limit < 1) {
            throw new \InvalidArgumentException('Limit must be positive');
        }
        $copy = clone $this;
        $copy->native = $this->native->limit($limit);
        $copy->restricted = true;
        return $copy;
    }
    /**
     * 设置非负结果偏移量，并标记为不支持批量写入的查询。
     *
     * @param int $offset 跳过的结果数量，必须不小于 0。
     * @return self 带偏移量的副本。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $query = $query->offset(25);
     */
    public function offset(int $offset): self
    {
        if ($offset < 0) {
            throw new \InvalidArgumentException('Offset must be nonnegative');
        }
        $copy = clone $this;
        $copy->native = $this->native->offset($offset);
        $copy->restricted = true;
        return $copy;
    }
    /**
     * 将调用方提供的投影表达式传给原生构建器，不对表达式进行字段白名单解析。
     *
     * @param string $fields 原生 SQL 投影或分组表达式，只能由可信代码提供。
     * @return self 设置投影后的查询副本。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $query = $query->select('id,name');
     */
    public function select(string $fields): self
    {
        $copy = clone $this;
        $copy->native = $this->native->select($fields);
        $copy->restricted = true;
        return $copy;
    }
    /**
     * 将可信分组表达式传给原生构建器，并限制该查询的批量写入。
     *
     * @param string $fields 原生 SQL 投影或分组表达式，只能由可信代码提供。
     * @return self 设置分组后的查询副本。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $query = $query->groupBy('name');
     */
    public function groupBy(string $fields): self
    {
        $copy = clone $this;
        $copy->native = $this->native->groupBy($fields);
        $copy->restricted = true;
        return $copy;
    }
    /**
     * 按模型声明恢复 PDO 读出的标量类型，保留 null 和未初始化属性。
     *
     * @param object $record 原生查询已填充但可能仍含字符串标量的模型对象。
     * @return object 完成类型恢复的同一模型对象。
     *
     * 内部调用示例（仅类内使用）：
     * @example $record = $this->typed($record);
     */
    private function typed(object $record): object
    {
        // PDO 将标量结果作为字符串传输；Origami 完成列到属性的填充后，
        // 恢复模型声明的类型。
        foreach ((new \ReflectionClass($record))->getProperties() as $property) {
            if (!$property->isPublic() || $property->isStatic() || !$property->isInitialized($record)) {
                continue;
            }
            $value = $property->getValue($record);
            $type = $property->getType();
            if ($value === null || !($type instanceof \ReflectionNamedType)) {
                continue;
            }
            $value = match ($type->getName()) {
                'int' => (int)$value, 'float' => (float)$value, 'bool' => (bool)$value, 'string' => (string)$value, default => $value,
            };
            $property->setValue($record, $value);
        }
        return $record;
    }
    /**
     * 执行当前查询并逐条恢复模型字段类型。
     *
     * 参数：无。
     *
     * @return array 模型实例列表，无结果时为空数组。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $users = $query->get();
     */
    public function get(): array
    {
        $records = [];
        foreach ($this->builder()->get() as $record) {
            $records[] = $this->typed($record);
        } return $records;
    }
    /**
     * 执行当前查询并读取首条模型记录。
     *
     * 参数：无。
     *
     * @return ?object 首条模型实例；没有记录时为 null。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $user = $query->first();
     */
    public function first(): ?object
    {
        $record = $this->builder()->first();
        return $record === null ? null : $this->typed($record);
    }
    /**
     * 读取首条模型记录；不存在时抛出 HTTP 404。
     *
     * 参数：无。
     *
     * @return object 首条模型实例。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $user = $query->firstOrFail();
     */
    public function firstOrFail(): object
    {
        $record = $this->first();
        if ($record === null) {
            throw new HttpError(404, '记录不存在');
        } return $record;
    }
    /**
     * 按单主键或完整复合主键读取首条记录。
     *
     * @param mixed $id 单主键值，或完整的主键属性名到值的映射。
     * @return ?object 匹配的模型实例；未找到时为 null。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $record = $query->find(1);
     */
    public function find(mixed $id): ?object
    {
        return $this->whereKey($id)->first();
    }
    /**
     * 按主键读取记录，未找到时抛出 HTTP 404。
     *
     * @param mixed $id 单主键值，或完整的主键属性名到值的映射。
     * @return object 匹配的模型实例。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $record = $query->findOrFail(1);
     */
    public function findOrFail(mixed $id): object
    {
        return $this->whereKey($id)->firstOrFail();
    }
    /**
     * 追加所有主键字段的等值条件；复合主键必须完整提供，主键值不能为 null。
     *
     * @param mixed $key 单主键值或完整复合主键映射，不允许缺失字段或 null。
     * @return self 带主键条件的查询副本。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $query = $query->whereKey(42);
     */
    public function whereKey(mixed $key): self
    {
        $class = $this->model;
        $fields = $class::primaryFields();
        if (!is_array($key)) {
            if (count($fields) !== 1) {
                throw new \InvalidArgumentException('Composite key must include all fields');
            }
            $key = [$fields[0] => $key];
        }
        if (count($key) !== count($fields)) {
            throw new \InvalidArgumentException('Invalid model key');
        }
        $query = $this;
        foreach ($fields as $field) {
            if (!array_key_exists($field, $key) || $key[$field] === null) {
                throw new \InvalidArgumentException('Missing key field: ' . $field);
            }
            $query = $query->where($field, $key[$field]);
        }
        return $query;
    }
    /**
     * 按属性构造并插入记录，然后按主键重新读取，取得数据库生成字段。
     *
     * @param array $attributes 模型属性名到待写入值的映射；字段必须存在于模型声明。
     * @return object 持久化后重新读取的模型实例。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $record = $query->create($attributes);
     */
    public function create(array $attributes): object
    {
        $class = $this->model;
        $record = $class::make($attributes);
        $result = $this->insert($record);
        $key = [];
        foreach ($class::primaryFields() as $field) {
            $key[$field] = $attributes[$field] ?? $result->insertId;
        }
        return $this->findOrFail($key);
    }
    /**
     * 检查当前查询是否至少存在一条记录。
     *
     * 参数：无。
     *
     * @return bool 存在为 true，否则 false。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $exists = $query->exists();
     */
    public function exists(): bool
    {
        return $this->first() !== null;
    }
    /**
     * 对当前条件执行 COUNT(*)，不使用分页上限缩小计数。
     *
     * 参数：无。
     *
     * @return int 匹配记录总数。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $total = $query->count();
     */
    public function count(): int
    {
        return (int)$this->aggregate(['aggregate' => ['COUNT', '*']])['aggregate'];
    }
    /**
     * 对映射字段求和，无匹配值时以 0 返回。
     *
     * @param string $field 已声明的模型属性名或列名，不接受任意 SQL 表达式。
     * @return int|float 整数或浮点数总和。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $total = \Loom\Models\Job::query()->sum('attempts');
     */
    public function sum(string $field): int|float
    {
        return 0 + ($this->aggregate(['aggregate' => ['SUM', $field]])['aggregate'] ?? 0);
    }
    /**
     * 根据别名到 [运算, 字段] 的结构生成聚合 SQL，支持 COUNT、SUM、MIN、MAX、AVG。
     *
     * @param array $expressions 聚合别名到 [COUNT/SUM/MIN/MAX/AVG, 字段] 的映射；COUNT 可用 *。
     * @return array 聚合别名到数据库返回值的关联数组；此方法只应用条件，不应用排序和分页。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $stats = $query->aggregate(['total' => ['COUNT', '*']]);
     */
    public function aggregate(array $expressions): array
    {
        $this->ensureConnection();
        $class = $this->model;
        $table = ModuleRegistry::attribute(new \ReflectionClass($class), \Database\Annotation\Table::class);
        if ($table === null) {
            throw new \LogicException('Count requires a Table annotation');
        }
        $select = [];
        foreach ($expressions as $alias => $expression) {
            [$operation, $field] = $expression;
            $operation = strtoupper($operation);
            if (!in_array($operation, ['COUNT', 'SUM', 'MIN', 'MAX', 'AVG'], true)) {
                throw new \InvalidArgumentException('Invalid aggregate');
            }
            $column = $operation === 'COUNT' && $field === '*' ? '*' : SqlIdentifier::quote($class::modelColumn($field));
            $select[] = $operation . '(' . $column . ') AS ' . SqlIdentifier::quote($alias);
        }
        if ($select === []) {
            throw new \InvalidArgumentException('Empty aggregate');
        }
        $sql = 'SELECT ' . implode(',', $select) . ' FROM ' . SqlIdentifier::quote($table->name);
        if ($this->conditions !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $this->conditions);
        }
        $rows = $this->native->query($sql, ...$this->arguments);
        $out = [];
        foreach ($expressions as $alias => $expression) {
            $out[$alias] = $rows[0]->{$alias};
        }
        return $out;
    }
    /**
     * 执行查询并把模型转为关联数组，可按列名筛选输出字段。
     *
     * @param array $columns 输出数据库列名列表；空列表保留所有字段。默认值为 []。
     * @return array 记录数组列表；columns 为空时保留所有已初始化字段。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $rows = $query->rows(['id', 'name']);
     */
    public function rows(array $columns = []): array
    {
        $rows = [];
        foreach ($this->get() as $model) {
            $row = $model->toArray();
            $rows[] = $columns === [] ? $row : array_intersect_key($row, array_flip($columns));
        } return $rows;
    }
    /**
     * 限制为一条结果并转换为关联数组。
     *
     * @param array $columns 输出数据库列名列表；空列表保留所有字段。默认值为 []。
     * @return ?array 首条记录数组；无结果时为 null。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $row = $query->firstRow(['id', 'name']);
     */
    public function firstRow(array $columns = []): ?array
    {
        $rows = $this->limit(1)->rows($columns);
        return $rows[0] ?? null;
    }
    /**
     * 追加参数化 IN 条件；空列表生成恒假条件而不是匹配全部记录。
     *
     * @param string $field 已声明的模型属性名或列名，不接受任意 SQL 表达式。
     * @param array $values IN 条件的值列表；空列表匹配零条记录。
     * @return self 包含新条件的查询副本。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $query = $query->whereIn('id', [1, 2, 3]);
     */
    public function whereIn(string $field, array $values): self
    {
        $class = $this->model;
        $column = SqlIdentifier::quote($class::modelColumn($field));
        $copy = clone $this;
        $copy->conditions[] = $values === [] ? '0=1' : $column . ' IN (' . implode(',', array_fill(0, count($values), '?')) . ')';
        foreach ($values as $value) {
            $copy->arguments[] = is_bool($value) ? (int)$value : $value;
        }
        return $copy;
    }
    /**
     * 读取指定映射字段并按记录顺序提取值。
     *
     * @param string $field 已声明的模型属性名或列名，不接受任意 SQL 表达式。
     * @return array 字段值列表。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $names = $query->pluck('name');
     */
    public function pluck(string $field): array
    {
        $class = $this->model;
        $column = $class::modelColumn($field);
        $values = [];
        foreach ($this->rows([$column]) as $row) {
            $values[] = $row[$column];
        }
        return $values;
    }
    /**
     * 读取指定页并计算总数，页码被限制在 1 至 100000 之间。
     *
     * @param int $page 页码，实际限制到 1 至 100000。默认值为 1。
     * @param array $columns 输出数据库列名列表；空列表保留所有字段。默认值为 []。
     * @param int $perPage 每页条数，必须大于 0。默认值为 25。
     * @return array 含 items 记录列表和 total 总数的数组。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $page = $query->paginate(2, ['id', 'name'], 25);
     */
    public function paginate(int $page = 1, array $columns = [], int $perPage = 25): array
    {
        return ['items' => $this->limit($perPage)->offset((max(1, min(100000, $page)) - 1) * $perPage)->rows($columns), 'total' => $this->count()];
    }
    /**
     * 构造实体并执行保留既有冲突行的插入，需要框架数据库适配。
     *
     * @param array $attributes 模型属性名到待写入值的映射；字段必须存在于模型声明。
     * @param array $unique 冲突检测所依据的模型字段列表；数据库必须存在对应唯一约束。
     * @return object 原生执行结果对象，包含 insertId 和 rowsAffected。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $result = $query->insertOrIgnore($attributes, ['email']);
     */
    public function insertOrIgnore(array $attributes, array $unique): object
    {
        $class = $this->model;
        return $this->insert($class::make($attributes), $unique);
    }
    /**
     * 构造实体并在唯一键冲突时更新指定字段，需要框架数据库适配。
     *
     * @param array $attributes 模型属性名到待写入值的映射；字段必须存在于模型声明。
     * @param array $unique 冲突检测所依据的模型字段列表；数据库必须存在对应唯一约束。
     * @param array $update 冲突时允许更新的模型字段列表。
     * @return object 原生执行结果对象，包含 insertId 和 rowsAffected。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $result = $query->upsert($attributes, ['email'], ['name']);
     */
    public function upsert(array $attributes, array $unique, array $update): object
    {
        $class = $this->model;
        return $this->insert($class::make($attributes), $unique, $update);
    }
    /**
     * 插入具体模型对象，保留已初始化的假值；自动生成且为 null 的字段不写入。
     *
     * @param object $entity 当前模型类型的已构造实体。
     * @param array $unique 冲突检测所依据的模型字段列表；数据库必须存在对应唯一约束。默认值为 []。
     * @param array $update 冲突时允许更新的模型字段列表。默认值为 []。
     * @return object 原生执行结果对象，包含 insertId 和 rowsAffected，并非模型实体。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $result = $query->insert($entity);
     */
    public function insert(object $entity, array $unique = [], array $update = []): object
    {
        $this->ensureConnection();
        $class = $this->model;
        if (!is_a($entity, $class)) {
            throw new \InvalidArgumentException('Wrong model type');
        }
        $values = $entity->toArray();
        foreach ((new \ReflectionClass($class))->getProperties() as $property) {
            if ($property->getAttributes(\Database\Annotation\GeneratedValue::class) !== [] && $property->isInitialized($entity) && $property->getValue($entity) === null) {
                unset($values[$class::modelColumn($property->getName())]);
            }
        }
        // Origami 1.0.8 的对象插入会丢弃假值。通过其参数化执行传输
        // 保留显式初始化的默认值。
        $table = ModuleRegistry::attribute(new \ReflectionClass($class), \Database\Annotation\Table::class);
        if ($table === null || $values === []) {
            throw new \InvalidArgumentException('Insert requires a mapped table and values');
        }
        $columns = [];
        $marks = [];
        $args = [];
        foreach ($values as $column => $value) {
            $columns[] = SqlIdentifier::quote($column);
            $marks[] = '?';
            $args[] = is_bool($value) ? (int)$value : $value;
        }
        $conflict = '';
        if ($unique !== []) {
            if ($this->database === null) {
                throw new \LogicException('Conflict writes require a framework database; use Model::using($database)');
            }
            $uniqueColumns = [];
            $updateColumns = [];
            foreach ($unique as $field) {
                $uniqueColumns[] = $class::modelColumn($field);
            }
            foreach ($update as $field) {
                $updateColumns[] = $class::modelColumn($field);
            }
            $conflict = $this->database->conflict($uniqueColumns, $updateColumns);
        }
        $result = $this->native->execute('INSERT INTO ' . SqlIdentifier::quote($table->name) . ' (' . implode(',', $columns) . ') VALUES (' . implode(',', $marks) . ')' . $conflict, ...$args);
        $result->insertId = $result->lastInsertId;
        return $result;
    }
    /**
     * 要求查询具有条件且未设置排序、限制、偏移、投影或分组，避免含糊批量写入。
     *
     * 参数：无。
     *
     * @return void 无返回值；不满足条件时抛出 LogicException。
     *
     * 内部调用示例（仅类内使用）：
     * @example $this->assertWrite();
     */
    private function assertWrite(): void
    {
        if ($this->conditions === [] || $this->restricted) {
            throw new \LogicException('Writes require a predicate and no order/limit/offset');
        }
    }
    /**
     * 按当前条件参数化更新字段，保留显式 null；拒绝空更新和未限定写入。
     *
     * @param array $attributes 模型属性名到待写入值的映射；字段必须存在于模型声明。
     * @return int 受影响行数。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $affected = $query->where('id', 1)->update(['name' => '小明']);
     */
    public function update(array $attributes): int
    {
        $this->ensureConnection();
        $this->assertWrite();
        $class = $this->model;
        if ($attributes === []) {
            throw new \InvalidArgumentException('Empty update');
        }
        // 原生对象更新会忽略 NULL。通过同一原生传输执行简短的赋值语句，
        // 以保留显式写入 null 的行为。
        $table = ModuleRegistry::attribute(new \ReflectionClass($class), \Database\Annotation\Table::class);
        $sets = [];
        $values = [];
        foreach ($attributes as $field => $value) {
            $sets[] = SqlIdentifier::quote($class::modelColumn($field)) . '=?';
            $values[] = is_bool($value) ? (int)$value : $value;
        }
        $result = $this->native->execute('UPDATE ' . SqlIdentifier::quote($table->name) . ' SET ' . implode(',', $sets) . ' WHERE ' . implode(' AND ', $this->conditions), ...array_merge($values, $this->arguments));
        return (int)$result->rowsAffected;
    }
    /**
     * 删除当前条件匹配的记录，执行前验证批量写入约束。
     *
     * 参数：无。
     *
     * @return int 受影响行数。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $affected = $query->where('id', 1)->delete();
     */
    public function delete(): int
    {
        $this->assertWrite();
        return (int)$this->builder()->delete()->rowsAffected;
    }
    /**
     * 取得应用当前条件后的原生构建器，供使用原生表达式、连接查询等高级能力。
     *
     * 参数：无。
     *
     * @return \Database\DB 原生 Database\DB，调用方负责其 SQL 表达式安全及作用域生命周期。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $native = $query->native();
     */
    public function native(): \Database\DB
    {
        return $this->builder();
    }
}
