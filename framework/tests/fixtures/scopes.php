<?php

declare(strict_types=1);

/**
 * 收集数据库、认证和配置依赖，验证作用域与配置注入。
 *
 * 构造参数：
 * - $database（Loom\Database）：当前作用域的数据库服务，负责连接、查询及事务。
 * - $auth（Loom\Auth）：当前作用域的认证服务，负责会话与身份校验。
 * - $config（Loom\Config）：项目配置实例，提供配置读取及项目路径解析。
 *
 * 返回值：使用 new 构造时得到 ScopedDependencies 实例；注册到容器时由容器管理实例的创建与复用。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $instance = $services->get(ScopedDependencies::class); // 在对应测试环境中解析；无效夹具用于断言异常
 */
final class ScopedDependencies
{
    public Loom\Database $database;
    public Loom\Auth $auth;
    public Loom\Config $config;
    #[Loom\InjectConfig('probe.label')]
    public string $label;
    /**
     * 初始化 ScopedDependencies 实例及其依赖。收集数据库、认证和配置依赖，验证作用域与配置注入。
     *
     * @param Loom\Database $database 当前作用域的数据库服务，负责连接、查询及事务。
     * @param Loom\Auth $auth 当前作用域的认证服务，负责会话与身份校验。
     * @param Loom\Config $config 项目配置实例，提供配置读取及项目路径解析。
     * @return void 无直接返回值；通过 new 表达式取得初始化后的实例。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $instance = $services->get(ScopedDependencies::class); // 在对应测试环境中解析；无效夹具用于断言异常
     */
    public function __construct(Loom\Database $database, Loom\Auth $auth, Loom\Config $config)
    {
        $this->database = $database;
        $this->auth = $auth;
        $this->config = $config;
    }
}

/**
 * 映射 scope_records 表，验证模型查询使用当前作用域数据库。
 *
 * 构造参数：本声明未定义构造参数；继承类型沿用父类的构造约定。
 *
 * 返回值：使用 new 构造时得到 ScopedRecord 实例；注册到容器时由容器管理实例的创建与复用。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $instance = $services->get(ScopedRecord::class); // 在对应测试环境中解析；无效夹具用于断言异常
 */
#[\Database\Annotation\Table('scope_records')]
final class ScopedRecord extends \Loom\Model
{
    #[\Database\Annotation\Id]
    #[\Database\Annotation\GeneratedValue]
    #[\Database\Annotation\Column('id', false)]
    public ?int $id = null;
    #[\Database\Annotation\Column('name', false)]
    public string $name;
}

/**
 * 通过连接编号和临时表验证并发定时器的数据库隔离。
 *
 * 构造参数：
 * - $database（Loom\Database）：当前作用域的数据库服务，负责连接、查询及事务。
 *
 * 返回值：抽象类不能直接实例化；创建具体子类后获得符合本类约定的对象。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $instance = $services->get(ScopedTimerProbe::class); // 在对应测试环境中解析；无效夹具用于断言异常
 */
abstract class ScopedTimerProbe
{
    public Loom\Database $database;
    public int $rows = 0;
    public int $connectionId = 0;
    /**
     * 初始化 ScopedTimerProbe 实例及其依赖。通过连接编号和临时表验证并发定时器的数据库隔离。
     *
     * @param Loom\Database $database 当前作用域的数据库服务，负责连接、查询及事务。
     * @return void 无直接返回值；通过 new 表达式取得初始化后的实例。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $instance = $services->get(ScopedTimerProbe::class); // 在对应测试环境中解析；无效夹具用于断言异常
     */
    public function __construct(Loom\Database $database)
    {
        $this->database = $database;
    }
    /**
     * 记录连接编号并创建临时表，短暂等待后统计数据以验证连接隔离。
     *
     * 参数：无。
     *
     * @return void 无返回值，记录 connectionId 和 rows。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $timer(); // 使用已注入数据库的具体子类
     */
    public function __invoke(): void
    {
        $this->connectionId = (int)$this->database->query('SELECT CONNECTION_ID() AS id')[0]['id'];
        $this->database->execute('CREATE TEMPORARY TABLE timer_probe(id INTEGER)');
        $this->database->execute('INSERT INTO timer_probe VALUES(1)');
        usleep(20000);
        $this->rows = count($this->database->query('SELECT * FROM timer_probe'));
    }
}

/**
 * 第一组定时器夹具，继承数据库隔离探针并以一秒周期运行。
 *
 * 构造参数：本声明未定义构造参数；继承类型沿用父类的构造约定。
 *
 * 返回值：使用 new 构造时得到 FirstScopedTimer 实例；注册到容器时由容器管理实例的创建与复用。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $instance = $services->get(FirstScopedTimer::class); // 在对应测试环境中解析；无效夹具用于断言异常
 */
#[Loom\Timer(1)]
final class FirstScopedTimer extends ScopedTimerProbe
{
}

/**
 * 第二组定时器夹具，用于和第一组比较独立连接。
 *
 * 构造参数：本声明未定义构造参数；继承类型沿用父类的构造约定。
 *
 * 返回值：使用 new 构造时得到 SecondScopedTimer 实例；注册到容器时由容器管理实例的创建与复用。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $instance = $services->get(SecondScopedTimer::class); // 在对应测试环境中解析；无效夹具用于断言异常
 */
#[Loom\Timer(1)]
final class SecondScopedTimer extends ScopedTimerProbe
{
}
