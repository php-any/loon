<?php

declare(strict_types=1);

namespace Loom;

// PDO 由 Origami std/php/pdo 提供。每个执行作用域拥有独立句柄。
/**
 * 按作用域持有 MySQL PDO 连接，并为原生 ORM 提供同一连接适配，统一事务与参数化 SQL 执行。
 *
 * 构造参数：
 * - $config（\Loom\Config）：项目配置实例，提供配置读取及项目路径解析。
 *
 * 返回值：使用 new 构造时得到 Database 实例；注册到容器时由容器管理实例的创建与复用。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $database = new Database($config); $rows = $database->query('SELECT id FROM loom_users WHERE id=?', [1]);
 */
final class Database
{
    private \Loom\Config $config;

    /**
     * 初始化 Database 实例及其依赖。按作用域持有 MySQL PDO 连接，并为原生 ORM 提供同一连接适配，统一事务与参数化 SQL 执行。
     *
     * @param \Loom\Config $config 项目配置实例，提供配置读取及项目路径解析。
     * @return void 无直接返回值；通过 new 表达式取得初始化后的实例。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $database = new Database($config); $rows = $database->query('SELECT id FROM loom_users WHERE id=?', [1]);
     */
    public function __construct(\Loom\Config $config)
    {
        $this->config = $config;
    }

    private ?\PDO $connection = null;
    private bool $transaction = false;
    private ?\Database\Sql\DB $modelAdapter = null;
    private string $modelName = '';

    /**
     * 惰性打开 PDO 并注册共享该连接的原生 ORM 适配器。
     *
     * 参数：无。
     *
     * @return string 当前作用域唯一的原生模型连接名称。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $name = $database->modelConnectionName();
     */
    public function modelConnectionName(): string
    {
        $this->connection();
        if ($this->modelAdapter === null) {
            $this->modelName = 'loom_' . bin2hex(random_bytes(16));
            $this->modelAdapter = \Loom\Native\modelConnection($this->modelName, $this);
        }
        return $this->modelName;
    }
    /**
     * 验证 MySQL DSN，读取环境账号密码，打开 PDO 并设置严格 SQL 模式及 READ COMMITTED 隔离级别。
     *
     * @param string $dsn mysql: 开头的 PDO DSN；账号密码从 LOOM_MYSQL_USER、LOOM_MYSQL_PASSWORD 读取。
     * @return \PDO 新 PDO 连接；此方法不直接替换本实例缓存句柄。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $pdo = $database->open('mysql:host=127.0.0.1;port=3306;dbname=loom;charset=utf8mb4');
     */
    public function open(string $dsn): \PDO
    {
        if (!str_starts_with($dsn, 'mysql:')) {
            throw new \InvalidArgumentException('Database requires a MySQL DSN');
        }
        $db = new \PDO($dsn, (string)getenv('LOOM_MYSQL_USER'), (string)getenv('LOOM_MYSQL_PASSWORD'), [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $db->exec("SET SESSION sql_mode='STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION,ANSI_QUOTES'");
        $db->exec('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
        return $db;
    }
    // 仅编译结构化的冲突元数据。绝不改写原始 query/execute SQL。
    /**
     * 根据结构化唯一键及更新列生成 MySQL 冲突写入片段，标识符先经过校验。
     *
     * @param array $unique 用于冲突处理的唯一键列名列表，不能为空；数据库需存在对应约束。
     * @param array $update 冲突时更新的列名列表；空列表保留已有行。默认值为 []。
     * @return string ON DUPLICATE KEY UPDATE SQL 片段；update 为空时执行保持原值的赋值。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $sql = $database->conflict(['email'], ['name']);
     */
    public function conflict(array $unique, array $update = []): string
    {
        if ($unique === []) {
            throw new \InvalidArgumentException('Conflict columns are required');
        }
        $keys = [];
        foreach ($unique as $column) {
            $keys[] = SqlIdentifier::quote($column);
        }
        if ($update === []) {
            return ' ON DUPLICATE KEY UPDATE ' . $keys[0] . '=' . $keys[0];
        }
        $sets = [];
        foreach ($update as $column) {
            $name = SqlIdentifier::quote($column);
            $sets[] = $name . '=VALUES(' . $name . ')';
        }
        return ' ON DUPLICATE KEY UPDATE ' . implode(',', $sets);
    }
    /**
     * 关闭旧连接后以指定 DSN 建立并保存新连接。
     *
     * @param string $path 用于替换当前连接的 MySQL DSN，并非文件系统路径。
     * @return void 无返回值。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $database->connect($config->get('database.dsn'));
     */
    public function connect(string $path): void
    {
        $this->close();
        $this->connection = $this->open($path);
    }
    /**
     * 取得当前 PDO，尚未连接时按 database.dsn 惰性建立。
     *
     * 参数：无。
     *
     * @return \PDO 当前作用域的 PDO 连接。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $pdo = $database->connection();
     */
    public function connection(): \PDO
    {
        if ($this->connection === null) {
            $this->connect($this->config->get('database.dsn'));
        }
        return $this->connection;
    }
    /**
     * 移除模型适配器并关闭 PDO，对未完成事务先回滚，之后清除内部句柄。
     *
     * 参数：无。
     *
     * @return void 无返回值；未连接时可安全调用。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $database->close();
     */
    public function close(): void
    {
        if ($this->modelAdapter !== null) {
            \Database\removeConnection($this->modelName);
            $this->modelAdapter->close();
            $this->modelAdapter = null;
            $this->modelName = '';
        }
        $connection = $this->connection;
        $this->connection = null;
        $this->transaction = false;
        if ($connection === null) {
            return;
        }
        try {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }
        } finally {
            $connection->close();
        }
    }
    /**
     * 预处理并执行查询，以关联数组读取全部记录，最后释放游标。
     *
     * @param string $sql SQL 模板，值应使用 ? 占位符。
     * @param array $args 按占位符顺序排列的绑定值。默认值为 []。
     * @return array 行数组列表，无结果时为空数组。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $rows = $database->query('SELECT id FROM loom_users WHERE id=?', [1]);
     */
    public function query(string $sql, array $args = []): array
    {
        $statement = $this->connection()->prepare($sql);
        try {
            $statement->execute($args);
            return $statement->fetchAll(\PDO::FETCH_ASSOC);
        } finally {
            $statement->closeCursor();
        }
    }
    /**
     * 执行参数化写语句并取得插入标识及影响行数，最后释放游标。
     *
     * @param string $sql SQL 模板，值应使用 ? 占位符。
     * @param array $args 按占位符顺序排列的绑定值。默认值为 []。
     * @return array 含 id 和 affected 的整数结果数组。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $result = $database->execute('UPDATE loom_users SET name=? WHERE id=?', ['小明', 1]);
     */
    public function execute(string $sql, array $args = []): array
    {
        $db = $this->connection();
        $statement = $db->prepare($sql);
        try {
            $statement->execute($args);
            return ['id' => (int) $db->lastInsertId(), 'affected' => $statement->rowCount()];
        } finally {
            $statement->closeCursor();
        }
    }
    /**
     * 开启单层事务并执行回调，成功提交、异常回滚；拒绝嵌套或外部已开启事务。
     *
     * @param callable $operation 无参事务回调，异常将触发回滚。
     * @return mixed operation 的返回值；失败重新抛出原异常。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $value = $database->transaction(fn () => $database->query('SELECT 1 AS value'));
     */
    public function transaction(callable $operation): mixed
    {
        if ($this->transaction) {
            throw new \LogicException('Nested transactions are not supported');
        }
        $db = $this->connection();
        if ($db->inTransaction()) {
            throw new \LogicException('An external PDO transaction is active');
        }
        if (!$db->beginTransaction()) {
            throw new \RuntimeException('Could not begin transaction');
        }
        $this->transaction = true;
        try {
            $result = $operation();
            if (!$db->commit()) {
                throw new \RuntimeException('Transaction commit failed');
            }
            return $result;
        } catch (\Throwable $error) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $error;
        } finally {
            $this->transaction = false;
        }
    }
    /**
     * 在同一事务内依次执行带 SQL 和参数的语句列表。
     *
     * @param array $statements 每项为包含 sql 字符串和 args 参数数组的语句定义。
     * @return void 无返回值，任一语句失败则回滚。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $database->batch([['sql' => 'UPDATE loom_users SET name=? WHERE id=?', 'args' => ['小明', 1]]]);
     */
    public function batch(array $statements): void
    {
        $this->transaction(function () use ($statements): void {
            foreach ($statements as $statement) {
                $this->execute($statement['sql'], $statement['args'] ?? []);
            }
        });
    }
}
