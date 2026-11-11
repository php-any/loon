<?php

declare(strict_types=1);

namespace Loom;

/**
 * 具体模型的公共查询入口。表和列仍由子类上的 Origami 注解声明。没有显式 Database 时，只接受进程里唯一的活动作用域。不能直接 new 这个抽象类。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $user = new \Loom\Models\User();
 */
abstract class Model
{
    private ?Database $relationDatabase = null;

    /** @var array<string, array<int, object>> */
    private array $relationCache = [];

    /**
     * 这个模型使用的连接名。null 或空字符串表示 config/database.php 的 default。
     */
    protected static ?string $connection = null;

    /**
     * 基于当前模型表创建原生投影。在 transaction() 回调里创建时绑定该事务，否则使用进程池。
     *
     * @param Database $database 提供连接名。它正处在事务回调里时，投影绑到那个事务。
     * @param string $resultClass 承接一行结果的类名，公共属性对应查询别名。
     * @return \Database\DB 绑定当前模型表及结果类的原生 DB 构建器。没有 Table 注解时抛 LogicException。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $query = static::project($database, \Loom\Results\CountResult::class);
     */
    protected static function project(Database $database, string $resultClass): \Database\DB
    {
        $table = ModuleDiscovery::attribute(new \ReflectionClass(static::class), \Database\Annotation\Table::class);
        if ($table === null) {
            throw new \LogicException('Projection requires a mapped model');
        }
        $database = static::resolveDatabase($database);
        return $database->bind(\Database\DB::model($resultClass, $database->modelConnectionName())->table($table->name));
    }

    /**
     * 选择模型要使用的数据库。未声明连接名时使用配置里的 default；声明了则使用同名连接。
     * 解析结果若就是传入的那个实例，事务仍留在该实例上。
     *
     * @param Database $database 当前作用域里的数据库。从它取出 default 或模型声明的连接。
     * @return Database 这次模型查询要绑定的数据库。
     *
     * 内部调用示例（仅类内使用）：
     * @example $database = static::resolveDatabase($database);
     */
    protected static function resolveDatabase(Database $database): Database
    {
        $name = static::$connection;
        if ($name === null || $name === '') {
            return $database->connection();
        }
        return $database->connection($name);
    }

    /**
     * 使用唯一活动作用域中的数据库创建查询。未声明 $connection 时使用 default。
     * 多个作用域同时存在时，改用 using() 传入该作用域的 Database。
     *
     * 参数：无。
     *
     * @return ModelQuery 当前具体模型的 ModelQuery。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $query = \Loom\Models\User::query();
     */
    public static function query(): ModelQuery
    {
        return static::using(ModelContext::database());
    }

    /**
     * 在指定数据库上注册或复用模型适配器并创建查询，避免隐式跨作用域连接。
     * 模型未声明连接名时改用 default；声明了则改用该连接。传入实例已经是目标连接时保持原对象。
     *
     * @param Database $database 查询要绑定的数据库。连接名和当前事务都从它取，不能为 null。
     * @return ModelQuery 绑定指定数据库的 ModelQuery。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $query = \Loom\Models\User::using($database);
     */
    public static function using(Database $database): ModelQuery
    {
        $database = static::resolveDatabase($database);
        $connection = $database->modelConnectionName();
        return new ModelQuery(static::class, \Database\DB::model(static::class, $connection), $connection, $database);
    }

    /**
     * 验证具体模型有 Table 注解且公共实例字段为受支持的标量类型，校验列名。
     *
     * 参数：无。
     *
     * @return void 无返回值；无效模型抛出异常。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example \Loom\Models\User::validateDefinition();
     */
    public static function validateDefinition(): void
    {
        $reflection = new \ReflectionClass(static::class);
        $table = ModuleDiscovery::attribute($reflection, \Database\Annotation\Table::class);
        if ($table === null || !$reflection->isInstantiable()) {
            throw new \LogicException('Model requires a concrete class and Table annotation');
        }
        SqlIdentifier::quote($table->name);
        foreach ($reflection->getProperties() as $property) {
            if (!$property->isPublic() || $property->isStatic()) {
                continue;
            }
            static::modelColumn($property->getName());
            $type = $property->getType();
            if (!($type instanceof \ReflectionNamedType) || !in_array($type->getName(), ['int', 'float', 'bool', 'string'], true)) {
                throw new \LogicException('Model fields must have scalar property types');
            }
        }
    }

    /**
     * 使用已经注册的原生连接名称创建查询，名称不存在时拒绝。
     *
     * @param string $connection 已经注册的原生连接名称。
     * @return ModelQuery 绑定原生连接的 ModelQuery，不含框架冲突写入适配。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $query = \Loom\Models\User::on($connectionName);
     */
    public static function on(string $connection): ModelQuery
    {
        if (\Database\getConnection($connection) === null) {
            throw new \InvalidArgumentException('Unknown model connection: ' . $connection);
        }
        return new ModelQuery(static::class, \Database\DB::model(static::class, $connection), $connection);
    }

    /**
     * 从公开实例属性及 Column 注解解析数据库列名，拒绝未知字段或不安全的列名。
     *
     * @param string $name 已声明的模型属性名或其数据库列名。
     * @return string 与属性或输入列名对应的数据库列名。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $column = \Loom\Models\User::modelColumn('email');
     */
    public static function modelColumn(string $name): string
    {
        foreach ((new \ReflectionClass(static::class))->getProperties() as $property) {
            if (!$property->isPublic() || $property->isStatic()) {
                continue;
            }
            $attributes = $property->getAttributes(\Database\Annotation\Column::class);
            $column = $attributes === [] ? $property->getName() : $attributes[0]->newInstance()->name;
            if ($column === '') {
                $column = $property->getName();
            }
            if ($name === $property->getName() || $name === $column) {
                if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $column)) {
                    throw new \InvalidArgumentException('Invalid model column: ' . $column);
                }
                return $column;
            }
        }
        throw new \InvalidArgumentException('Unknown model field: ' . $name);
    }

    /**
     * 在唯一活动作用域上新建查询，并加上这一条比较。两个实参时第二个是值；三个实参时第二个是运算符。没有唯一作用域时抛 LogicException。
     *
     * @param string $field 这次比较的模型属性或列名，不能是 SQL 表达式。
     * @param mixed $operator 三个实参时是 =、!=、<>、<、<=、>、>=、LIKE 之一；两个实参时它就是要比较的值。
     * @param mixed $value 三个实参时的比较值。布尔会变成 0 或 1。省略时为 null，这时只允许等于或不等于。
     * @return ModelQuery 带上这条条件的新查询。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $query = \Loom\Models\User::where('id', '>=', 1);
     */
    public static function where(string $field, mixed $operator, mixed $value = null): ModelQuery
    {
        return func_num_args() === 2 ? static::query()->where($field, $operator) : static::query()->where($field, $operator, $value);
    }

    /**
     * 取得单字段主键，复合主键不允许退化为一个字段。
     *
     * 参数：无。
     *
     * @return string 唯一主键属性名；复合主键抛出 LogicException。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $field = \Loom\Models\User::primaryField();
     */
    public static function primaryField(): string
    {
        $fields = static::primaryFields();
        if (count($fields) !== 1) {
            throw new \LogicException('Composite keys require all key fields');
        }
        return $fields[0];
    }

    /**
     * 读取 Id 注解的属性列表，没有声明时按约定使用 id。
     *
     * 参数：无。
     *
     * @return array 主键属性名列表，保留声明顺序。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $fields = \Loom\Models\MigrationRecord::primaryFields();
     */
    public static function primaryFields(): array
    {
        $fields = [];
        foreach ((new \ReflectionClass(static::class))->getProperties() as $property) {
            if ($property->getAttributes(\Database\Annotation\Id::class) !== []) {
                $fields[] = $property->getName();
            }
        }
        return $fields === [] ? ['id'] : $fields;
    }

    /**
     * 用唯一活动作用域按主键取第一条。复合主键必须给出全部字段。没有记录时返回 null。
     *
     * @param mixed $id 单主键的值，或主键属性名到值的完整映射。
     * @return ?object 匹配的模型实例；未找到时为 null。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $user = \Loom\Models\User::find(1);
     */
    public static function find(mixed $id): ?object
    {
        return static::query()->find($id);
    }

    /**
     * 用唯一活动作用域按主键取记录。没有记录时抛 HTTP 404。
     *
     * @param mixed $id 单主键的值，或主键属性名到值的完整映射。
     * @return object 匹配的模型实例。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $user = \Loom\Models\User::findOrFail(1);
     */
    public static function findOrFail(mixed $id): object
    {
        $model = static::find($id);
        if ($model === null) {
            throw new HttpError(404, '记录不存在');
        }
        return $model;
    }

    /**
     * 读取唯一活动作用域里这个模型的全部记录。没有记录时是空数组。
     *
     * 参数：无。
     *
     * @return array 模型实例列表，没有记录时为空数组。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $users = \Loom\Models\User::all();
     */
    public static function all(): array
    {
        return static::query()->get();
    }

    /**
     * 确认对象是当前模型后，用唯一活动作用域插入。类型不符抛 InvalidArgumentException。
     *
     * @param object $entity 要插入的当前模型实例。
     * @return object 插入结果，含 insertId 和 rowsAffected，不是模型实体。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $result = \Loom\Models\User::insert($user);
     */
    public static function insert(object $entity): object
    {
        if (!is_a($entity, static::class)) {
            throw new \InvalidArgumentException('Expected an instance of ' . static::class);
        }
        return static::query()->insert($entity);
    }

    /**
     * 解析 whereXxx 动态方法并验证恰好一个位置参数，先按驼峰属性再按下划线列名匹配。
     *
     * @param string $method 动态方法名称，例如 whereEmail。
     * @param array $arguments 动态方法的位置参数列表，whereXxx 要求恰好一个值。
     * @return string 经过模型映射验证的列名。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $field = \Loom\Models\User::dynamicField('whereEmail', ['user@example.com']);
     */
    public static function dynamicField(string $method, array $arguments): string
    {
        if (!preg_match('/^where([A-Z][A-Za-z0-9_]*)$/D', $method, $matches)) {
            throw new \BadMethodCallException('Unknown model method: ' . $method);
        }
        if (count($arguments) !== 1 || !array_key_exists(0, $arguments)) {
            throw new \InvalidArgumentException($method . ' expects one positional value');
        }
        try {
            return static::modelColumn(lcfirst($matches[1]));
        } catch (\InvalidArgumentException $error) {
            return static::modelColumn(strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $matches[1])));
        }
    }
    /**
     * 将受支持的查询快捷方法委托给查询器，其他方法按 whereXxx 条件解析。
     *
     * @param string $method 要转发的名字。get、first、firstOrFail、rows、firstRow、select、groupBy、orderBy、limit、offset、count、exists、sum、pluck、paginate、whereIn、update、delete、insertOrIgnore、upsert、aggregate 原样交给查询；其余按 whereXxx 解析。
     * @param array $arguments 原样展开给被转发的方法。走 whereXxx 时必须恰好有一个值。
     * @return mixed 转发结果。whereXxx 得到 ModelQuery；名单里的方法得到那个方法自己的返回值。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $query = \Loom\Models\User::whereEmail('user@example.com');
     */
    public static function __callStatic(string $method, array $arguments): mixed
    {
        if (in_array($method, ['get', 'first', 'firstOrFail', 'rows', 'firstRow', 'select', 'groupBy', 'orderBy', 'limit', 'offset', 'count', 'exists', 'sum', 'pluck', 'paginate', 'whereIn', 'update', 'delete', 'insertOrIgnore', 'upsert', 'aggregate', 'with', 'has', 'whereHas', 'whereDoesntHave'], true)) {
            return static::query()->{$method}(...$arguments);
        }
        return static::where(static::dynamicField($method, $arguments), $arguments[0]);
    }

    /**
     * 在唯一活动作用域上创建查询并登记预加载关系。
     *
     * @param mixed ...$relations 关系名或带约束的关联数组项。
     */
    public static function with(mixed ...$relations): ModelQuery
    {
        return static::query()->with(...$relations);
    }

    public function setRelationDatabase(?Database $database): void
    {
        $this->relationDatabase = $database;
    }

    public function relationDatabase(): Database
    {
        return $this->relationDatabase ?? ModelContext::database();
    }

    public function hasRelationCache(string $name): bool
    {
        return array_key_exists($name, $this->relationCache);
    }

    /**
     * @return array<int, object>
     */
    public function relationCache(string $name): array
    {
        return $this->relationCache[$name] ?? [];
    }

    /**
     * @param array<int, object> $records
     */
    public function storeRelationCache(string $name, array $records): void
    {
        $this->relationCache[$name] = $records;
    }

    public function forgetRelationCache(string $name): void
    {
        unset($this->relationCache[$name]);
    }

    /**
     * 在当前实例上预加载关系。
     *
     * @param mixed ...$relations
     */
    public function load(mixed ...$relations): void
    {
        $database = $this->relationDatabase();
        RelationLoader::eagerLoad([$this], RelationLoader::parse($relations), $database);
    }

    protected function belongsTo(string $related, ?string $foreignKey = null, ?string $ownerKey = null): BelongsTo
    {
        $relation = new BelongsTo($this, $related, $this->relationDatabase(), $foreignKey, $ownerKey);
        $relation->setName($this->guessRelationName());
        return $relation;
    }

    protected function hasOne(string $related, ?string $foreignKey = null, ?string $localKey = null): HasOne
    {
        $relation = new HasOne($this, $related, $this->relationDatabase(), $foreignKey, $localKey);
        $relation->setName($this->guessRelationName());
        return $relation;
    }

    protected function hasMany(string $related, ?string $foreignKey = null, ?string $localKey = null): HasMany
    {
        $relation = new HasMany($this, $related, $this->relationDatabase(), $foreignKey, $localKey);
        $relation->setName($this->guessRelationName());
        return $relation;
    }

    protected function belongsToMany(
        string $related,
        string $pivotTable,
        ?string $foreignPivotKey = null,
        ?string $relatedPivotKey = null,
        ?string $localKey = null,
    ): BelongsToMany {
        $relation = new BelongsToMany($this, $related, $this->relationDatabase(), $pivotTable, $foreignPivotKey, $relatedPivotKey, $localKey);
        $relation->setName($this->guessRelationName());
        return $relation;
    }

    private function guessRelationName(): string
    {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3);
        return $trace[1]['function'] ?? 'relation';
    }
    /**
     * 遍历已初始化的公共实例属性，按映射列名输出数据。
     *
     * 参数：无。
     *
     * @return array 数据库列名到属性值的关联数组，不包含未初始化属性。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $row = $user->toArray();
     */
    public function toArray(): array
    {
        $row = [];
        foreach ((new \ReflectionClass($this))->getProperties() as $property) {
            if ($property->isPublic() && !$property->isStatic() && $property->isInitialized($this)) {
                $row[static::modelColumn($property->getName())] = $property->getValue($this);
            }
        }
        return $row;
    }
    /**
     * 用唯一活动作用域按这些属性插入，再按主键回读生成后的行。未声明的字段抛 InvalidArgumentException。要靠数据库生成的主键多于一个时抛 LogicException。
     *
     * @param array $attributes 属性名到初值。未给出的主键里只能有一个靠自增回填。
     * @return object 回读到的模型实例。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $record = \Loom\Models\User::create($attributes);
     */
    public static function create(array $attributes): object
    {
        return static::query()->create($attributes);
    }
    /**
     * 构造具体模型并按给定属性名赋值，验证字段但不写数据库。
     *
     * @param array $attributes 模型属性名到待写入值的映射；字段必须存在于模型声明。
     * @return object 未持久化的模型对象。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $user = \Loom\Models\User::make(['name' => '小明', 'email' => 'user@example.com']);
     */
    public static function make(array $attributes): object
    {
        $class = static::class;
        $record = new $class();
        foreach ($attributes as $field => $value) {
            static::modelColumn($field);
            (new \ReflectionProperty($class, $field))->setValue($record, $value);
        }
        return $record;
    }
}
