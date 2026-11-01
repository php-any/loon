# 基础模型

模型继承 `Loom\Model`，表、列和主键沿用 Origami 的数据库注解。框架根据 `config/database.php` 的 `dsn`（或 `LOOM_DATABASE` 覆盖值）自动初始化连接，业务不需要注册连接，也不需要注入 EntityManager。

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

`ModelQuery` 是原生 `Database\DB` 的便捷适配器。每个条件返回新查询，连续条件按 AND 累积，再一次性交给原生 ORM，避免原生 where 的条件替换行为。查询映射和实体回填使用语言层 ORM；适配层将 PDO 字符串结果恢复为模型声明的标量类型。

- `get()` / `all()` 返回模型数组，`first()` / `find()` 可返回 null，`firstOrFail()` / `findOrFail()` 未命中抛出 404。
- `rows()` 返回以数据库列名为键的数组；单个模型使用 `toArray()`。
- `rows(['id', 'name'])` 和 `firstRow(['id', 'name'])` 限定输出字段；用户模型包含密码字段，接口必须显式选择可公开字段。
- 连续 `orderBy()` 累积排序字段，`paginate($page, $columns, $perPage)` 返回 `items` 和 `total`。
- `count()` 统计全部匹配行，不受排序、limit、offset 影响；`exists()` 检查是否存在。
- `create($attributes)` 接受属性名，插入并返回回填后的新模型。业务应传入已筛选和校验的字段，不能直接传整个请求。
- `insert($model)` 返回 `success / insertId / rowsAffected`，不修改传入实例。
- `Model::insertOrIgnore($attributes, $unique)` 和 `Model::upsert($attributes, $unique, $update)` 自动使用当前作用域连接，返回写入结果；冲突键与更新字段支持属性别名。数据库必须存在对应的唯一约束。查询创建后保留所属数据库，冲突写入与普通写入共享事务。
- `update($attributes)` 接受属性名或列名，`delete()` 返回影响行数。两者必须有条件，不能附带排序、limit、offset 或 select/groupBy。
- 插入保留显式初始化的 0、false、空字符串、null；未初始化字段交给数据库默认值。带 `GeneratedValue` 的 null 字段会省略。更新保留显式 null。Origami v1.0.8 的对象写入会丢弃部分这些值，因此这些写操作通过原生参数化 execute 完成。

复杂原生表达式可通过 `User::query()->native()` 使用，`select($fields)` 和 `groupBy($fields)` 也保留原生字符串参数；这些 SQL 片段必须来自可信代码。绕过适配器后采用原生语义，包括 where 替换条件、原生写入规则和无条件写入行为。

## 自动连接与事务

首次模型操作解析当前框架作用域中的 `Loom\Database`，沿用既有配置、MySQL 凭据与按需连接逻辑。宿主扩展 `runtime/internal/extensions/model.go` 提供 SQL 传输适配：原生 ORM 使用唯一命名连接调用同一个 Database/PDO，不额外建立物理连接。

因此 `Database::transaction()` 回调中的模型操作与原始 SQL 共用事务，提交、回滚与外键语义均保持一致。作用域关闭时移除命名连接并关闭适配器；先前创建的查询不能重新打开已关闭连接。

HTTP 请求和定时器在调用入口绑定各自数据库，并在 finally 中恢复。普通命令、作业和脚本使用框架创建的当前作用域。一个自定义进程同时持有多个作用域或自行 spawn 时，使用 `$scope->run(fn () => User::whereName('TEST')->first())` 明确调用所属作用域，支持嵌套及并发。查询创建后固定使用创建时的连接。

`User::on($registeredName)` 保留高级命名连接入口，未知名称立即报错；普通业务不需要它。默认模型连接完全由框架管理，不能替换为一个跨请求共用的 default 连接。

## 应用迁移与边界

应用模型位于 `app/Models/`，按身份、权限、项目、订单、通知、系统配置和发布验收划分；基础设施模型位于 `framework/src/Database/Models/`。当前持久化表均有对应模型，`loom_model_coverage_test.php` 检查 MySQL 迁移后的全部持久化表及列映射。

项目、发布验收和订单的主要操作，以及权限、账号安全和通知模块的普通写入已使用模型。应用服务、控制器、作业与仓储通过模型操作持久化数据；跨表投影使用原生 ORM，必要的并发 SQL 收在具体模型方法中。旧 EntityManager、EntityMetadata 和 Query 已移除，应用统一使用 Model / ModelQuery。

基础设施或显式持有数据库实例的服务使用 `Model::using($database)`，把模型操作绑定到该服务的事务。`insertOrIgnore($attributes, $unique)` 与 `upsert($attributes, $unique, $update)` 在此绑定上使用数据库冲突处理，保留通知去重等原子性，不以先查询再插入替代唯一约束。

模型字段应声明在具体模型上；当前原生 ORM 不完整支持从业务父类继承字段。`all()` 没有隐式 1000 条上限，大表请显式 limit。此基类不提供关系预加载、自动迁移或实例 save 的脏字段追踪。

增加了宿主扩展，拉取源码后需运行 `./scripts/build.ps1` 重新构建运行时。

迁移账本通过 `MigrationRecord` 模型操作，建表、迁移文件中的 DDL 和 MySQL 迁移互斥锁仍由迁移服务执行。队列入队、完成及重试状态写入和周期运行记录使用模型，领取作业的必要原子 SQL 已收在 Job 模型方法中。

关联条件属于具体模型或仓储。例如 `Role::forUser($database, $userId)`、`UserRole::hasActiveAdministrator($database)` 使用 `using($database)->native()->select(...)->join(...)->where(...)->get()/first()`，由 Origami 生成 JOIN 并回填基表对象。查询只选择基表字段，避免关联表同名列覆盖；参数仍绑定，不把用户输入拼入 SQL 片段。

原生 v1.0.8 没有条件 upsert、原子递增、RETURNING 或 FOR UPDATE SKIP LOCKED 的便捷接口。以下模型保留参数绑定的专用 SQL，而服务调用明确的模型方法：AuthLimit::recordAttempt 保持限流窗口原子重置/递增；User::revokeSessions 原子增加会话版本；Job::acceptRedisLease 保持 Redis Stream 消费与 MySQL 租约竞争语义；ScheduledJob::storeCron / storeInterval 保持同配置不推迟下次执行，due 在 MySQL 下锁定到期行。普通读取优先复用原生查询器；这些专用方法不是第二套通用 ORM。

账号令牌的锁定由 `AccountToken::lockOwner` 执行，发送冷却由 `AuthLimit::acquireCooldown` 执行，调用方事务覆盖随后删除令牌和账号变更。`Delivery::claim` 内部完成通知领取事务，`Mail::claim` 使用调用方事务；MySQL 通过锁行防止重复领取。网络投递在事务之外执行。

跨表返回字段多于基表模型时，使用只读结果类，例如 `App\Queries\Identity\AccountStatus`、`App\Queries\Notifications\Message` 和 `Loom\Results\SessionIdentity`。它们只有结果属性和显式序列化，不声明 Table，不参与表映射，也没有持久化方法。表模型的受保护 `project()` 只把自己的表名和作用域连接传给原生 `DB::model(...)->table(...)`；原生查询器负责 SELECT/JOIN/WHERE 和回填，不另建查询编译器。

管理用户、审计和通知投递列表通过模型提供分页，运行监控使用原生 GROUP BY 及调度作业 JOIN。`OperationsData::page` 的任意 SQL 拼接接口已删除。`loom_read_projection_test.php` 覆盖分页总数、用户扩展信息缺失时的 LEFT JOIN 行为、搜索绑定以及敏感字段排除。

