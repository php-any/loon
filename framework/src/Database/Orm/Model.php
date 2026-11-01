<?php

declare(strict_types=1);

namespace Loom;

/**
 * 模型基类，连接选择和便利查询由 Loom 包装，表及列映射仍使用 Origami 原生注解。静态方法应在具体模型子类上调用。
 *
 * 构造参数：无，本类型未声明构造参数。
 *
 * 返回值：抽象类不能直接实例化；创建具体子类后获得符合本类约定的对象。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $user = \Loom\Models\User::find(1);
 */
abstract class Model
{
    /**
     * 基于当前模型表创建映射到结果类的原生投影查询，保持显式数据库连接。
     *
     * @param Database $database 显式数据库服务，保证 ORM 与原始 SQL 使用同一事务；查询构造时可为 null。
     * @param string $resultClass 接收投影结果的完整类名，其公共属性应对应查询别名。
     * @return \Database\DB 绑定当前模型表及结果类的原生 DB 构建器。
     *
     * 内部调用示例（仅类内使用）：
     * @example $query = static::project($database, \Loom\Results\CountResult::class);
     */
    protected static function project(Database $database, string $resultClass): \Database\DB
    {
        $table = ModuleRegistry::attribute(new \ReflectionClass(static::class), \Database\Annotation\Table::class);
        if ($table === null) {
            throw new \LogicException('Projection requires a mapped model');
        }
        return \Database\DB::model($resultClass, $database->modelConnectionName())->table($table->name);
    }

    /**
     * 使用活动模型作用域中的数据库创建查询。
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
     *
     * @param Database $database 显式数据库服务，保证 ORM 与原始 SQL 使用同一事务；查询构造时可为 null。
     * @return ModelQuery 绑定指定数据库的 ModelQuery。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $query = \Loom\Models\User::using($database);
     */
    public static function using(Database $database): ModelQuery
    {
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
        $table = ModuleRegistry::attribute($reflection, \Database\Annotation\Table::class);
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
     * 添加映射字段的比较条件；双参数调用表示等值，null 仅允许等于或不等于。
     *
     * @param string $field 已声明的模型属性名或列名，不接受任意 SQL 表达式。
     * @param mixed $operator 三参数形式为比较运算符：=、!=、<>、<、<=、>、>=、LIKE；双参数形式为等值比较的值。
     * @param mixed $value 比较值，布尔值转为 0/1，null 仅用于相等或不等谓词。默认值为 null。
     * @return ModelQuery 带新条件的 ModelQuery；不修改原查询。
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
     * 按单主键或完整复合主键读取首条记录。
     *
     * @param mixed $id 单主键值，或完整的主键属性名到值的映射。
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
     * 按主键读取记录，未找到时抛出 HTTP 404。
     *
     * @param mixed $id 单主键值，或完整的主键属性名到值的映射。
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
     * 查询当前模型全部记录并恢复声明的属性类型。
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
     * 插入具体模型对象，保留已初始化的假值；自动生成且为 null 的字段不写入。
     *
     * @param object $entity 当前模型类型的已构造实体。
     * @return object 原生执行结果对象，包含 insertId 和 rowsAffected，并非模型实体。
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
     * @param string $method 动态方法名称，例如 whereEmail。
     * @param array $arguments 动态方法的位置参数列表，whereXxx 要求恰好一个值。
     * @return mixed 被委托方法的结果；动态 whereXxx 返回 ModelQuery。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $query = \Loom\Models\User::whereEmail('user@example.com');
     */
    public static function __callStatic(string $method, array $arguments): mixed
    {
        if (in_array($method, ['get', 'first', 'firstOrFail', 'select', 'groupBy', 'orderBy', 'limit', 'offset', 'count', 'exists', 'insertOrIgnore', 'upsert'], true)) {
            return static::query()->{$method}(...$arguments);
        }
        return static::where(static::dynamicField($method, $arguments), $arguments[0]);
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
     * 按属性构造并插入记录，然后按主键重新读取，取得数据库生成字段。
     *
     * @param array $attributes 模型属性名到待写入值的映射；字段必须存在于模型声明。
     * @return object 持久化后重新读取的模型实例。
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
