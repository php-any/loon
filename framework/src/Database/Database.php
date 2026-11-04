<?php

declare(strict_types=1);

namespace Loom;

/**
 * 进程级 MySQL 连接池的脚本入口。config/database.php 用 default 和 connections 声明多条连接。
 * 池按驱动 DSN 复用；普通语句结束还连接。事务只存在于这条连接自己的 transaction 回调里。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $database = new Database($config);
 */
final class Database
{
    private \Loom\Config $config;
    private ?string $name = null;
    private ?self $manager = null;
    /** @var array<string, self> */
    private array $opened = [];
    private ?string $source = null;
    private ?string $registered = null;
    private ?\Database\Sql\Tx $tx = null;
    /** @var array<string, \Database\Sql\DB> */
    private static array $pools = [];

    /**
     * 只保存配置。不打开连接，也不占有池里的物理连接。
     *
     * @param \Loom\Config $config 从 database.default 和 database.connections 读取连接；只有 dsn 时把它当作名为 mysql 的默认连接。
     * @return void 不返回值。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $database = new Database($config);
     */
    public function __construct(\Loom\Config $config)
    {
        $this->config = $config;
    }

    /**
     * 返回这条实例对应的配置连接名。默认实例使用 database.default；没有该项时使用 mysql。
     *
     * 参数：无。
     *
     * @return string connections 中的连接名。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $name = $database->name();
     */
    public function name(): string
    {
        if ($this->name !== null) {
            return $this->name;
        }
        $default = $this->config->get('database.default');
        if (is_string($default) && $default !== '') {
            return $default;
        }
        return 'mysql';
    }

    /**
     * 取出同一作用域里的命名连接。省略名字时回到配置里的默认连接。
     * 同一个名字始终返回同一个实例，因此这条连接的事务留在该实例上。
     *
     * @param ?string $name connections 里的连接名。null 或空字符串表示默认连接。默认值为 null。
     * @return self 绑定该名字的数据库。未知名字抛出 InvalidArgumentException。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $reports = $database->connection('reporting');
     */
    public function connection(?string $name = null): self
    {
        if ($this->manager !== null) {
            return $this->manager->connection($name);
        }
        if ($name === null || $name === '') {
            $name = $this->name();
        }
        if ($name === $this->name()) {
            return $this;
        }
        if (!isset($this->opened[$name])) {
            $child = new self($this->config);
            $child->name = $name;
            $child->manager = $this;
            $child->driverDsn();
            $this->opened[$name] = $child;
        }
        return $this->opened[$name];
    }

    /**
     * 把当前 DSN 的进程池注册为这条连接的名字。同一个 DSN 只打开一次池。
     *
     * 参数：无。
     *
     * @return string 原生模型连接名称，与 name() 相同。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $name = $database->modelConnectionName();
     */
    public function modelConnectionName(): string
    {
        $dsn = $this->driverDsn();
        if ($this->registered !== $dsn) {
            $this->register($this->name());
            $this->registered = $dsn;
        }
        return $this->name();
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
     * 校验 mysql: DSN。这里只检查写法，账号密码仍从 LOOM_MYSQL_USER、LOOM_MYSQL_PASSWORD 读取。
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
     * 返回当前实例使用的 database/sql DSN。connect() 覆盖过的实例用那条 DSN，否则用命名连接的配置。
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
        return self::mysqlDriverDsn($this->configuredPdoDsn(), $this->username(), $this->password());
    }

    /**
     * 返回当前连接的 mysql: DSN，不含账号密码。default 连接的这个值用来隔开 Redis 键。
     *
     * 参数：无。
     *
     * @return string mysql:host=...;dbname=... 或带 unix_socket 的 DSN。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $dsn = $database->pdoDsn();
     */
    public function pdoDsn(): string
    {
        return $this->configuredPdoDsn();
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
        $socket = $parts['unix_socket'] ?? '';
        $name = $parts['dbname'] ?? '';
        $port = $parts['port'] ?? '3306';
        $charset = $parts['charset'] ?? 'utf8mb4';
        if ($name === '' || !preg_match('/^[A-Za-z0-9_]+$/D', $name) || !preg_match('/^[A-Za-z0-9_]+$/D', $charset)) {
            throw new \InvalidArgumentException('Database requires a MySQL DSN');
        }
        if ($socket !== '') {
            if (!preg_match('#\A[A-Za-z0-9_./\\\\:-]+\z#', $socket) || str_contains($socket, '..')) {
                throw new \InvalidArgumentException('Database requires a MySQL DSN');
            }
            $address = 'unix(' . $socket . ')';
        } elseif ($host === '' || !preg_match('/^[A-Za-z0-9_.:-]+$/D', $host) || !preg_match('/^[0-9]+$/D', $port)) {
            throw new \InvalidArgumentException('Database requires a MySQL DSN');
        } else {
            $address = 'tcp(' . $host . ':' . $port . ')';
        }
        return rawurlencode($user) . ':' . rawurlencode($password) . '@' . $address . '/' . $name
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
     * @param string $path 要改用的 mysql: DSN。名字虽叫 path，这里不是文件路径。
     * @return void 无返回值。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $database->connect('mysql:host=127.0.0.1;port=3306;dbname=loom;charset=utf8mb4');
     */
    public function connect(string $path): void
    {
        self::mysqlDriverDsn($path, $this->username(), $this->password());
        $this->source = $path;
        $this->registered = null;
        $this->modelConnectionName();
    }

    /**
     * 读取这条连接在配置中的定义。只有 database.dsn 的旧配置会变成默认连接。
     *
     * 参数：无。
     *
     * @return array 该连接的 driver、dsn 或主机字段，以及可选的账号。
     *
     * 内部调用示例（仅类内使用）：
     * @example $definition = $this->definition();
     */
    private function definition(): array
    {
        $name = $this->name();
        $connections = $this->config->get('database.connections');
        if (is_array($connections) && isset($connections[$name]) && is_array($connections[$name])) {
            $definition = $connections[$name];
            $dsn = $definition['dsn'] ?? null;
            if (is_string($dsn) && $dsn !== '') {
                return $definition;
            }
            return $this->applyUrl($definition);
        }
        $dsn = $this->config->get('database.dsn');
        $default = $this->config->get('database.default');
        $defaultName = is_string($default) && $default !== '' ? $default : 'mysql';
        if (is_string($dsn) && $dsn !== '' && $name === $defaultName) {
            return ['driver' => 'mysql', 'dsn' => $dsn];
        }
        throw new \InvalidArgumentException('Unknown database connection: ' . $name);
    }

    /**
     * 得到这条连接的 mysql: DSN。connect() 指定过的值优先。连接配置里的 dsn 非空时直接使用，并优先于 url；否则有 Laravel url 时用合并后的主机和库名。
     *
     * 参数：无。
     *
     * @return string 不含账号密码的 mysql: DSN。
     *
     * 内部调用示例（仅类内使用）：
     * @example $pdo = $this->configuredPdoDsn();
     */
    private function configuredPdoDsn(): string
    {
        if ($this->source !== null) {
            return $this->source;
        }
        $definition = $this->definition();
        $driver = strtolower((string)($definition['driver'] ?? 'mysql'));
        if ($driver !== 'mysql' && $driver !== 'mariadb') {
            throw new \InvalidArgumentException('Database requires a MySQL connection');
        }
        $dsn = $definition['dsn'] ?? null;
        if (is_string($dsn) && $dsn !== '') {
            if (!str_starts_with($dsn, 'mysql:')) {
                throw new \InvalidArgumentException('Database requires a MySQL DSN');
            }
            return $dsn;
        }
        $url = $definition['url'] ?? null;
        if (is_string($url) && $url !== '') {
            $definition = $this->applyUrl($definition);
        }
        $database = (string)($definition['database'] ?? '');
        $charset = (string)($definition['charset'] ?? 'utf8mb4');
        $socket = (string)($definition['unix_socket'] ?? '');
        if ($socket !== '') {
            return 'mysql:unix_socket=' . $socket . ';dbname=' . $database . ';charset=' . $charset;
        }
        $host = (string)($definition['host'] ?? '');
        $port = (string)($definition['port'] ?? '3306');
        return 'mysql:host=' . $host . ';port=' . $port . ';dbname=' . $database . ';charset=' . $charset;
    }

    /**
     * 把 Laravel 的 url 写回连接字段。url 里出现的用户、密码、主机、端口、库名、字符集和套接字覆盖同名配置。
     *
     * @param array $definition connections 中的一项。
     * @return array 合并 url 之后的连接定义。url 不是 MySQL 时抛出 InvalidArgumentException。
     *
     * 内部调用示例（仅类内使用）：
     * @example $definition = $this->applyUrl($definition);
     */
    private function applyUrl(array $definition): array
    {
        $url = $definition['url'] ?? null;
        if (!is_string($url) || $url === '') {
            return $definition;
        }
        $parsed = parse_url($url);
        if ($parsed === false) {
            throw new \InvalidArgumentException('Database requires a MySQL connection');
        }
        $scheme = strtolower((string)($parsed['scheme'] ?? ''));
        if ($scheme !== '' && $scheme !== 'mysql') {
            throw new \InvalidArgumentException('Database requires a MySQL connection');
        }
        if (isset($parsed['user'])) {
            $definition['username'] = rawurldecode((string)$parsed['user']);
        }
        if (isset($parsed['pass'])) {
            $definition['password'] = rawurldecode((string)$parsed['pass']);
        }
        if (isset($parsed['host']) && $parsed['host'] !== '') {
            $definition['host'] = $parsed['host'];
        }
        if (isset($parsed['port'])) {
            $definition['port'] = (string)$parsed['port'];
        }
        $path = (string)($parsed['path'] ?? '');
        if ($path !== '' && $path !== '/') {
            $definition['database'] = ltrim($path, '/');
        }
        if (isset($parsed['query'])) {
            parse_str((string)$parsed['query'], $query);
            foreach (['charset' => 'charset', 'unix_socket' => 'unix_socket'] as $from => $to) {
                if (isset($query[$from]) && is_string($query[$from]) && $query[$from] !== '') {
                    $definition[$to] = $query[$from];
                }
            }
        }
        return $definition;
    }

    /**
     * 读取这条连接的用户名。配置缺这个键时使用 LOOM_MYSQL_USER。
     *
     * 参数：无。
     *
     * @return string MySQL 用户名，可以是空字符串。
     *
     * 内部调用示例（仅类内使用）：
     * @example $user = $this->username();
     */
    private function username(): string
    {
        return $this->credential('username', 'LOOM_MYSQL_USER');
    }

    /**
     * 读取这条连接的密码。配置缺这个键时使用 LOOM_MYSQL_PASSWORD。
     *
     * 参数：无。
     *
     * @return string MySQL 密码，可以是空字符串。
     *
     * 内部调用示例（仅类内使用）：
     * @example $password = $this->password();
     */
    private function password(): string
    {
        return $this->credential('password', 'LOOM_MYSQL_PASSWORD');
    }

    /**
     * 读取连接配置中的账号字段。值为 null 或缺键时改读环境变量。
     *
     * @param string $key 连接配置里的 username 或 password。
     * @param string $env 缺键时读取的环境变量名。
     * @return string 配置中的字符串；环境变量也不存在时为空字符串。
     *
     * 内部调用示例（仅类内使用）：
     * @example $user = $this->credential('username', 'LOOM_MYSQL_USER');
     */
    private function credential(string $key, string $env): string
    {
        $definition = $this->definition();
        if (array_key_exists($key, $definition) && $definition[$key] !== null) {
            return (string)$definition[$key];
        }
        $value = getenv($env);
        return $value === false ? '' : (string)$value;
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
     * @param string $sql 要读取的语句，值用 ? 占位。
     * @param array $args 按 ? 的顺序绑定的值。默认值为 []。
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
     * @param string $sql 要写入的语句，值用 ? 占位。
     * @param array $args 按 ? 的顺序绑定的值。默认值为 []。
     * @return array 含 id 和 affected 的整数结果。id 来自这条语句自己的自增主键。
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
     * @param callable $operation 这一次事务里要跑的无参回调。它抛出的异常会先回滚再原样抛出。
     * @return mixed 回调的返回值。已有事务时抛 LogicException，不支持嵌套。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $value = $database->transaction(fn () => 'ok');
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
     * @param ?\Database\Sql\Tx $tx 要绑定的事务。null 时查询器留在连接池上。默认值为 null。
     * @return \Database\DB 可执行 query 和 execute 的查询器。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $runner = $database->runner($tx);
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
     * @example $bound = $database->bind($builder);
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
     * 使用示例（依赖对象由所在应用或测试环境提供）：
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
