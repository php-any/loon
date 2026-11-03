<?php

declare(strict_types=1);

namespace Loom;

/**
 * 进程级 MySQL 连接池的脚本入口。池、事务和查询都使用 Origami 的 Database\Sql 与 Database\DB。
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
    private ?string $source = null;
    private ?string $registered = null;
    private ?\Database\Sql\Tx $tx = null;
    /** @var array<string, \Database\Sql\DB> */
    private static array $pools = [];

    /**
     * 保存配置。连接池属于进程，不在这个实例上占有物理连接。
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

    /**
     * 把当前 DSN 的进程池注册为连接名 loom。同一个 DSN 只打开一次池。
     *
     * 参数：无。
     *
     * @return string 原生模型连接名称。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $name = $database->modelConnectionName();
     */
    public function modelConnectionName(): string
    {
        $dsn = $this->driverDsn();
        if ($this->registered !== $dsn) {
            $this->register('loom');
            $this->registered = $dsn;
        }
        return 'loom';
    }

    /**
     * 用当前进程池注册一个连接名。测试和原生模型通过这个名字取得同一条池。
     *
     * @param string $name 连接名，由调用方保证不与其他 DSN 冲突。
     * @return void 无返回值。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $database->register('model_test');
     */
    public function register(string $name): void
    {
        \Database\registerConnection($name, $this->pool());
    }

    /**
     * 返回这次回调正在使用的事务。回调外面为 null。
     *
     * 参数：无。
     *
     * @return ?\Database\Sql\Tx 当前实例上的事务；没有事务时为 null。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $tx = $database->activeTransaction();
     */
    public function activeTransaction(): ?\Database\Sql\Tx
    {
        return $this->tx;
    }

    /**
     * 当前实例是否正处在自己的 transaction() 回调里。
     *
     * 参数：无。
     *
     * @return bool 回调期间为 true。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $inside = $database->inTransaction();
     */
    public function inTransaction(): bool
    {
        return $this->tx !== null;
    }

    /**
     * 校验 mysql: DSN。账号密码仍从 LOOM_MYSQL_USER、LOOM_MYSQL_PASSWORD 读取。
     *
     * @param string $dsn mysql: 开头的 DSN。
     * @return void 无返回值；非 MySQL DSN 抛出 InvalidArgumentException。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $database->open('mysql:host=127.0.0.1;port=3306;dbname=loom;charset=utf8mb4');
     */
    public function open(string $dsn): void
    {
        self::mysqlDriverDsn($dsn, (string)getenv('LOOM_MYSQL_USER'), (string)getenv('LOOM_MYSQL_PASSWORD'));
    }

    /**
     * 返回当前实例使用的 database/sql DSN。
     *
     * 参数：无。
     *
     * @return string 已编码的 MySQL DSN。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $dsn = $database->driverDsn();
     */
    public function driverDsn(): string
    {
        return self::mysqlDriverDsn($this->source ?? (string)$this->config->get('database.dsn'), (string)getenv('LOOM_MYSQL_USER'), (string)getenv('LOOM_MYSQL_PASSWORD'));
    }

    /**
     * 把 mysql:host=;port=;dbname=;charset= 转成 database/sql 使用的 DSN。
     *
     * @param string $pdo mysql: 开头的 DSN，不含账号密码。
     * @param string $user MySQL 用户名。
     * @param string $password MySQL 密码。
     * @return string database/sql DSN。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $driver = Database::mysqlDriverDsn('mysql:host=127.0.0.1;port=3306;dbname=loom;charset=utf8mb4', 'loom', 'secret');
     */
    public static function mysqlDriverDsn(string $pdo, string $user, string $password): string
    {
        if (!str_starts_with($pdo, 'mysql:')) {
            throw new \InvalidArgumentException('Database requires a MySQL DSN');
        }
        $parts = [];
        foreach (explode(';', substr($pdo, 6)) as $item) {
            if ($item === '') {
                continue;
            }
            $split = strpos($item, '=');
            if ($split === false) {
                throw new \InvalidArgumentException('Database requires a MySQL DSN');
            }
            $parts[strtolower(substr($item, 0, $split))] = substr($item, $split + 1);
        }
        $host = $parts['host'] ?? '';
        $name = $parts['dbname'] ?? '';
        $port = $parts['port'] ?? '3306';
        $charset = $parts['charset'] ?? 'utf8mb4';
        if ($host === '' || $name === '' || !preg_match('/^[A-Za-z0-9_.:-]+$/D', $host) || !preg_match('/^[A-Za-z0-9_]+$/D', $name) || !preg_match('/^[0-9]+$/D', $port) || !preg_match('/^[A-Za-z0-9_]+$/D', $charset)) {
            throw new \InvalidArgumentException('Database requires a MySQL DSN');
        }
        return rawurlencode($user) . ':' . rawurlencode($password) . '@tcp(' . $host . ':' . $port . ')/' . $name
            . '?charset=' . rawurlencode($charset)
            . '&sql_mode=' . rawurlencode("'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION,ANSI_QUOTES'")
            . '&transaction_isolation=' . rawurlencode("'READ-COMMITTED'");
    }

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
     * 记录随后查询使用的 DSN，并把它的连接池注册给原生 ORM。
     *
     * @param string $path MySQL DSN，并非文件系统路径。
     * @return void 无返回值。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $database->connect($config->get('database.dsn'));
     */
    public function connect(string $path): void
    {
        $this->open($path);
        $this->source = $path;
        $this->registered = null;
        $this->modelConnectionName();
    }

    /**
     * 连接池属于进程。关闭作用域时调用此方法不会关掉池，也不会回滚别的请求。
     *
     * 参数：无。
     *
     * @return void 无返回值。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $database->close();
     */
    public function close(): void
    {
    }

    /**
     * 执行参数化查询。事务回调里使用该事务，否则语句结束就把连接还回进程池。
     *
     * @param string $sql SQL 模板，值应使用 ? 占位符。
     * @param array $args 按占位符顺序排列的绑定值。默认值为 []。
     * @return array 行数组列表，无结果时为空数组。标量以字符串返回，null 保持 null。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $rows = $database->query('SELECT id FROM loom_users WHERE id=?', [1]);
     */
    public function query(string $sql, array $args = []): array
    {
        $rows = [];
        foreach ($this->runner($this->tx)->query($sql, $args) as $row) {
            $rows[] = get_object_vars($row);
        }
        return $rows;
    }

    /**
     * 执行参数化写语句。事务回调里使用该事务，否则语句结束即归还连接。
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
        $result = $this->runner($this->tx)->execute($sql, $args);
        return ['id' => (int)$result->lastInsertId, 'affected' => (int)$result->rowsAffected];
    }

    /**
     * 开启这一次回调的事务。回调里新创建的模型和原始 SQL 使用这个事务；提交或回滚后连接回池。
     *
     * @param callable $operation 无参事务回调，异常将触发回滚。
     * @return mixed operation 的返回值；失败重新抛出原异常。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $value = $database->transaction(fn () => $database->query('SELECT 1 AS value'));
     */
    public function transaction(callable $operation): mixed
    {
        if ($this->tx !== null) {
            throw new \LogicException('Nested transactions are not supported');
        }
        $tx = $this->pool()->begin();
        $this->tx = $tx;
        try {
            $result = $operation();
            $tx->commit();
            return $result;
        } catch (\Throwable $error) {
            try {
                $tx->rollback();
            } catch (\Throwable $rollbackError) {
            }
            throw $error;
        } finally {
            $this->tx = null;
        }
    }

    /**
     * 取得命名连接上的查询器。传入事务时，查询器改在该 *sql.Tx 上执行。
     *
     * @param ?\Database\Sql\Tx $tx 查询创建时捕获的事务；没有事务时使用连接池。默认值为 null。
     * @return \Database\DB 可执行 query 和 execute 的查询器。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $rows = $database->runner($database->activeTransaction())->query('SELECT 1 AS value', []);
     */
    public function runner(?\Database\Sql\Tx $tx = null): \Database\DB
    {
        $builder = \Database\DB::connection($this->modelConnectionName());
        if ($tx === null) {
            return $builder;
        }
        return \Database\bind($builder, $tx);
    }

    /**
     * 让一条已经建好的原生查询使用当前事务。回调外面原样返回。
     *
     * @param \Database\DB $builder 原生查询器。
     * @return \Database\DB 绑定当前事务后的同一个查询器；没有事务时不变。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $query = $database->bind(\Database\DB::connection('loom'));
     */
    public function bind(\Database\DB $builder): \Database\DB
    {
        if ($this->tx === null) {
            return $builder;
        }
        return \Database\bind($builder, $this->tx);
    }

    /**
     * 按 DSN 打开或复用进程级连接池。会话变量由驱动在物理连接建立时设置。
     *
     * 参数：无。
     *
     * @return \Database\Sql\DB 进程级 *sql.DB。
     *
     * 内部调用示例（仅类内使用）：
     * @example $pool = $this->pool();
     */
    private function pool(): \Database\Sql\DB
    {
        $dsn = $this->driverDsn();
        if (!isset(self::$pools[$dsn])) {
            $pool = \Database\Sql\open('mysql', $dsn);
            $pool->setMaxIdleConns(8);
            self::$pools[$dsn] = $pool;
        }
        return self::$pools[$dsn];
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
