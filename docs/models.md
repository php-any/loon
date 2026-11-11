# 基础模型

模型继承 `Loom\Model`，表、列和主键沿用 Origami 的数据库注解。没有声明 `$connection` 的模型直接使用 `config/database.php` 的 `default`。业务不需要注册连接，也不需要注入 EntityManager。要固定到另一个库时声明 `protected static ?string $connection = 'reporting'`。

```php
use Loom\Model;
use Database\Annotation\Table;
use Database\Annotation\Id;

#[Table('users')]
final class User extends Model
{
    #[Id]
    public int $id;
    public string $name;
    public int $team_id;
}

$user = User::whereName('TEST')->first();
$users = User::whereTeamId(7)->whereName('TEST')->orderBy('id', 'DESC')->get();
$user = User::findOrFail(1);
$user = User::create(['name' => 'TEST', 'team_id' => 7]);
User::whereTeamId(7)->whereId($user->id)->update(['name' => 'UPDATED']);
User::whereTeamId(7)->whereId($user->id)->delete();
```

`whereXxx($value)` 支持 camelCase 属性、snake_case 列及 `Column` 别名，接受一个位置参数。`where($field, $value)` 默认等于，也支持 `where($field, $operator, $value)`。空值使用 IS NULL / IS NOT NULL，值始终绑定；未知字段、运算符和方法会报错。组合方法名如 `whereNameAndTeamId` 暂不支持，使用连续 `whereName()->whereTeamId()`。

## 查询与写入

`ModelQuery` 在查询对象上累积条件和绑定参数，再生成参数化 SQL。读取结果按模型声明恢复标量类型。默认执行器是进程级连接池。在 `transaction()` 回调里创建的查询捕获该 `*sql.Tx`；回调外面已经建好的查询仍用创建时的执行器。语言层的 `Database\bind` 把查询器绑到这个事务上。

- `get()` / `all()` 返回模型数组，`first()` / `find()` 可返回 null，`firstOrFail()` / `findOrFail()` 未命中抛出 404。
- `rows()` 返回以数据库列名为键的数组；单个模型使用 `toArray()`。
- `rows(['id', 'name'])` 和 `firstRow(['id', 'name'])` 限定输出字段；用户模型包含密码字段，接口必须显式选择可公开字段。
- 连续 `orderBy()` 累积排序字段，`paginate($page, $columns, $perPage)` 返回 `items` 和 `total`。
- `count()` 统计全部匹配行，不受排序、limit、offset 影响；`exists()` 检查是否存在。
- `create($attributes)` 接受属性名，插入并按主键回读。自增主键取自这条插入语句的结果。业务应传入已筛选和校验的字段，不能直接传整个请求。
- `insert($model)` 返回 `insertId / rowsAffected`，不修改传入实例。
- `Model::insertOrIgnore($attributes, $unique)` 和 `Model::upsert($attributes, $unique, $update)` 使用查询创建时的执行器，返回写入结果；冲突键与更新字段支持属性别名。数据库必须存在对应的唯一约束。
- `update($attributes)` 接受属性名或列名，`delete()` 返回影响行数。两者必须有条件，不能附带排序、limit、offset 或 select/groupBy。
- 插入保留显式初始化的 0、false、空字符串、null；未初始化字段交给数据库默认值。带 `GeneratedValue` 的 null 字段会省略。更新保留显式 null。

复杂连接和投影可通过 `User::query()->native()` 使用。`native()` 和 `project()` 走进程池里的原生查询器，不自动加入当前事务。`select($fields)` 和 `groupBy($fields)` 的 SQL 片段必须来自可信代码。

## 连接池与事务

连接池是进程级的 `Database\Sql\open` 结果。普通 `query`、`execute` 和模型语句结束就把连接还回池。`Database::transaction()` 调用 `begin()`，只在该回调期间保存返回的 `*sql.Tx`；回调里新创建的模型和原始 SQL 使用它，提交或回滚后连接回池。已经在回调外面建好的查询不改执行器。迁移锁放在同一次事务回调里：`GET_LOCK` 留在这条被事务占用的连接上，结束前 `RELEASE_LOCK`。会话级 `sql_mode` 和 `transaction_isolation` 写在驱动 DSN 里，物理连接建立时设置一次。

`ServiceScope` 管理这一次请求、消息、调用、命令、任务或节拍里的服务实例。它不拥有数据库连接。查询上的条件和绑定参数只属于这条查询。静态模型（`User::find()`、`Task::query()`）使用当前执行帧上绑定的 Database：`ServiceScope::run()` 和 `ServiceProvider::withScope()` 在回调期间绑定本作用域的 Database，宿主为每个工作单元都这样调用。绑定跟着子调用传下去，回调结束后恢复，并发的请求互不可见。在作用域之外调用静态模型会抛出 `LogicException`；自建脚本用 `$app->services->withScope(...)`，或用 `ModelContext::during($database, $callback)` 绑定指定的 Database。要绕开当前绑定时，把 Database 传给 `Model::using($database)`。模型没声明连接名时，`query()` 和 `using()` 都取 `default`；声明了则取同名连接。解析到的若就是传入的那个实例，事务仍留在上面。同一条驱动 DSN 的实例共享进程池；`$database->connection('reporting')` 在这个作用域里始终返回同一个实例。

异步 `spawn` 不继承正在进行的事务或持有的连接。需要独立事务时，在新的调用里自行 `transaction()`。

`$database->connection('reporting')` 按 `config/database.php` 的 `connections` 取出命名连接，未知名称立即报错。`User::on($registeredName)` 仍要求该名字已经注册到原生连接；普通业务用 `connection()` 或模型上的 `$connection`。

## 应用迁移与边界

应用模型位于 `app/Models/`，按身份、权限、项目、订单、通知、系统配置和发布验收划分；基础设施模型位于 `framework/src/Database/Models/`。当前持久化表均有对应模型，`loom_model_coverage_test.php` 检查 MySQL 迁移后的全部持久化表及列映射。

项目、发布验收和订单的主要操作，以及权限、账号安全和通知模块的普通写入已使用模型。应用服务、控制器、作业与仓储通过模型操作持久化数据；跨表投影使用原生 ORM，必要的并发 SQL 收在具体模型方法中。旧 EntityManager、EntityMetadata 和 Query 已移除，应用统一使用 Model / ModelQuery。

普通业务查询直接写 `Task::whereUserId($id)->delete()`。它使用当前作用域的数据库；查询创建在 `transaction()` 回调里时，使用该回调的事务。必须使用另一个 Database 实例时，才把它传给 `Model::using($database)`。`insertOrIgnore($attributes, $unique)` 与 `upsert($attributes, $unique, $update)` 使用查询创建时的数据库做冲突处理，保留通知去重等原子性，不以先查询再插入替代唯一约束。

模型字段应声明在具体模型上；当前原生 ORM 不完整支持从业务父类继承字段。`all()` 没有隐式 1000 条上限，大表请显式 limit。此基类不提供自动迁移或实例 `save()` 的脏字段追踪。

## 关系

关系通过**公共实例方法**定义，返回 `BelongsTo`、`HasOne`、`HasMany` 或 `BelongsToMany` 对象；不要在模型上声明关系属性，也不要用魔术访问。可在返回对象上继续 `where` / `orderBy` / `limit` 等，再 `first()` 或 `get()`（`belongsTo` / `hasOne` 的 `get()` 仍是数组，最多一条）。

```php
public function user(): \Loom\BelongsTo
{
    return $this->belongsTo(\Loom\Models\User::class);
}

public function items(): \Loom\HasMany
{
    return $this->hasMany(OrderItem::class);
}

public function tags(): \Loom\BelongsToMany
{
    return $this->belongsToMany(RelTag::class, 'rel_post_tag');
}

$order->user()->first();
$order->items()->where('quantity', '>=', 1)->get();
Order::with('user', 'items')->get();
Order::with('user.account')->get();
$order->load('items');
```

在受保护方法 `belongsTo` / `hasOne` / `hasMany` / `belongsToMany` 上省略键名时：`belongsTo` 使用父表 `snake(关联类短名)_id` 作外键，关联表单字段主键作属主键；`hasOne` / `hasMany` 在子表使用 `snake(父类短名)_id`，父表单字段主键作本地键；`belongsToMany` **必须**提供真实中间表名，省略时中间表键默认为 `{父短名}_id` 与 `{关联短名}_id`。父模型为复合主键且未显式给出单字段本地/属主键、或父子连接名不一致、或关系方法不存在/未返回 `Relation` 时抛出 `LogicException`。

**缓存：** 在父实例上，对**未再加条件**的同一关系，首次 `get()` / `first()` 的结果会缓存；之后同样不加分支的 `get()` / `first()` 不再查库。一旦链上带有 `where`、`orderBy`、`limit` 等，则发新查询且不用缓存。

**存在性过滤**（走参数化 `EXISTS` / `NOT EXISTS`，参与 `transaction()` 中的模型查询）：

- `Model::has('items')` / `whereHas('items', $constraint)` / `whereDoesntHave('items', $constraint)`
- 也可在 `ModelQuery` 上链式调用；空 `whereIn` 列表按无匹配行处理（不会生成非法 `IN ()`）。

**关系写入（不含父行 `save()`）：** `hasOne` / `hasMany` 的 `create($attributes)` 会写入外键；`belongsToMany` 提供 `attach` / `detach` / `sync`（仅中间表两列键，无 `withPivot`）。未实现 `belongsTo` 的 `associate` / `dissociate`。

**尚未支持：** 脏字段追踪、实例 `save()`、嵌套事务、morph*、`hasManyThrough`、关系属性访问、`withCount`、中间表额外列、级联删除。

增加了宿主扩展，拉取源码后需运行 `./scripts/build.ps1` 重新构建运行时。

迁移账本通过 `MigrationRecord` 模型操作，建表、迁移文件中的 DDL 和 MySQL 迁移互斥锁仍由迁移服务执行。队列入队、完成及重试状态写入和周期运行记录使用模型，领取作业的必要原子 SQL 已收在 Job 模型方法中。

关联条件属于具体模型或仓储。例如 `Role::forUser($database, $userId)`、`UserRole::hasActiveAdministrator($database)` 使用 `using($database)->native()->select(...)->join(...)->where(...)->get()/first()`，由 Origami 生成 JOIN 并回填基表对象。查询只选择基表字段，避免关联表同名列覆盖；参数仍绑定，不把用户输入拼入 SQL 片段。

原生 v1.0.8 没有条件 upsert、原子递增、RETURNING 或 FOR UPDATE SKIP LOCKED 的便捷接口。以下模型保留参数绑定的专用 SQL，而服务调用明确的模型方法：AuthLimit::recordAttempt 保持限流窗口原子重置/递增；User::revokeSessions 原子增加会话版本；Job::acceptRedisLease 保持 Redis Stream 消费与 MySQL 租约竞争语义；ScheduledJob::storeCron / storeInterval 保持同配置不推迟下次执行，due 在 MySQL 下锁定到期行。普通读取优先复用原生查询器；这些专用方法不是第二套通用 ORM。

账号令牌的锁定由 `AccountToken::lockOwner` 执行，发送冷却由 `AuthLimit::acquireCooldown` 执行，调用方事务覆盖随后删除令牌和账号变更。`Delivery::claim` 内部完成通知领取事务，`Mail::claim` 使用调用方事务；MySQL 通过锁行防止重复领取。网络投递在事务之外执行。

跨表返回字段多于基表模型时，使用只读结果类，例如 `App\Queries\Identity\AccountStatus`、`App\Queries\Notifications\Message` 和 `Loom\Results\SessionIdentity`。它们只有结果属性和显式序列化，不声明 Table，不参与表映射，也没有持久化方法。表模型的受保护 `project()` 把表名和进程池中的原生连接交给 `DB::model(...)->table(...)`。原生查询器负责 SELECT/JOIN/WHERE 和回填。它不加入 `transaction()` 回调里的事务。

管理用户、审计和通知投递列表通过模型提供分页，运行监控使用原生 GROUP BY 及调度作业 JOIN。`OperationsData::page` 的任意 SQL 拼接接口已删除。`loom_read_projection_test.php` 覆盖分页总数、用户扩展信息缺失时的 LEFT JOIN 行为、搜索绑定以及敏感字段排除。

