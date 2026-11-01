<?php

declare(strict_types=1);

namespace Loom;

/**
 * 按命名空间及批次执行成对 SQL 迁移，校验历史内容并以 MySQL 命名锁串行化执行。
 *
 * 构造参数：
 * - $database（\Loom\Database）：当前作用域的数据库服务，负责连接、查询及事务。
 * - $config（\Loom\Config）：项目配置实例，提供配置读取及项目路径解析。
 *
 * 返回值：使用 new 构造时得到 Migrations 实例；注册到容器时由容器管理实例的创建与复用。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $plan = $migrations->files($config->path('database/migrations')); $migrations->run('studio', $plan);
 */
final class Migrations
{
    private \Loom\Database $database;
    private \Loom\Config $config;

    /**
     * 初始化 Migrations 实例及其依赖。按命名空间及批次执行成对 SQL 迁移，校验历史内容并以 MySQL 命名锁串行化执行。
     *
     * @param \Loom\Database $database 当前作用域的数据库服务，负责连接、查询及事务。
     * @param \Loom\Config $config 项目配置实例，提供配置读取及项目路径解析。
     * @return void 无直接返回值；通过 new 表达式取得初始化后的实例。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $plan = $migrations->files($config->path('database/migrations')); $migrations->run('studio', $plan);
     */
    public function __construct(\Loom\Database $database, \Loom\Config $config)
    {
        $this->database = $database;
        $this->config = $config;
    }

    /**
     * 按文件名排序读取目录中的 up/down SQL 对，缺失 down 文件立即报错。
     *
     * @param string $directory 含成对 *.up.sql 和 *.down.sql 文件的目录，不递归扫描。
     * @return array 每项含 version、up、down 的有序迁移计划。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $plan = $migrations->files($config->path('database/migrations'));
     */
    public function files(string $directory): array
    {
        $files = glob($directory . '/*.up.sql');
        sort($files, SORT_STRING);
        $plan = [];
        foreach ($files as $file) {
            $version = basename($file, '.up.sql');
            $down = $directory . '/' . $version . '.down.sql';
            if (!is_file($down)) {
                throw new \LogicException('Missing down migration: ' . $version);
            }
            $plan[] = ['version' => $version, 'up' => file_get_contents($file), 'down' => file_get_contents($down)];
        }
        return $plan;
    }
    /**
     * 验证计划顺序、非空 SQL 及历史校验和，要求已执行版本是计划前缀。
     *
     * @param string $namespace 非空迁移命名空间；与已有迁移历史保持一致。
     * @param array $plan 按版本严格升序排列的迁移数组，每项含 version、up、down。
     * @return array 以版本为键的已应用迁移记录。
     *
     * 内部调用示例（仅类内使用）：
     * @example $applied = $this->inspect('studio', $plan);
     */
    private function inspect(string $namespace, array $plan): array
    {
        if ($namespace === '') {
            throw new \LogicException('Migration namespace is required');
        }
        $known = [];
        $previous = '';
        foreach ($plan as $migration) {
            $version = $migration['version'];
            if ($version === '' || $migration['up'] === '' || $migration['down'] === '' || ($previous !== '' && strcmp($previous, $version) >= 0)) {
                throw new \LogicException('Migration versions must be strictly ordered with up/down SQL');
            }
            $known[$version] = hash('sha256', $migration['up'] . "\0" . $migration['down']);
            $previous = $version;
        }
        $seen = [];
        foreach (Models\MigrationRecord::using($this->database)->whereNamespace($namespace)->orderBy('version')->rows(['version', 'checksum', 'batch', 'applied_at']) as $row) {
            if (!isset($known[$row['version']]) || $known[$row['version']] !== $row['checksum']) {
                throw new \LogicException('Migration drift: ' . $namespace . '/' . $row['version']);
            }
            $seen[$row['version']] = $row;
        }
        $missing = false;
        foreach ($plan as $migration) {
            if (!isset($seen[$migration['version']])) {
                $missing = true;
            } elseif ($missing) {
                throw new \LogicException('Migration history is not a prefix of the plan');
            }
        }
        return $seen;
    }
    /**
     * 创建保存迁移版本、校验和、批次和应用时间的记录表。
     *
     * 参数：无。
     *
     * @return void 无返回值；表已存在时保持不变。
     *
     * 内部调用示例（仅类内使用）：
     * @example $this->ledger();
     */
    private function ledger(): void
    {
        $this->database->execute('CREATE TABLE IF NOT EXISTS loom_migrations(namespace VARCHAR(120) NOT NULL,version VARCHAR(120) NOT NULL,checksum CHAR(64) NOT NULL,batch BIGINT NOT NULL,applied_at BIGINT NOT NULL,PRIMARY KEY(namespace,version)) ENGINE=InnoDB');
    }
    /**
     * 取得数据库命名锁后应用未执行迁移，或逆序撤销最后批次；DDL 不被伪装为事务。
     *
     * @param string $namespace 非空迁移命名空间；与已有迁移历史保持一致。
     * @param array $plan 按版本严格升序排列的迁移数组，每项含 version、up、down。
     * @param bool $down false 应用待执行版本；true 撤销最后一个已应用批次。默认值为 false。
     * @return void 无返回值，变更数据库及迁移记录；异常仍释放锁。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $migrations->run('studio', $plan, false);
     */
    public function run(string $namespace, array $plan, bool $down = false): void
    {
        $this->ledger();
        $operation = function () use ($namespace, $plan, $down): void {
            $seen = $this->inspect($namespace, $plan);
            $batch = 0;
            foreach ($seen as $row) {
                $batch = max($batch, (int) $row['batch']);
            }
            if ($down) {
                foreach (array_reverse($plan) as $migration) {
                    $version = $migration['version'];
                    if (isset($seen[$version]) && (int) $seen[$version]['batch'] === $batch) {
                        $this->executeSql($migration['down']);
                        Models\MigrationRecord::using($this->database)->whereNamespace($namespace)->whereVersion($version)->delete();
                    }
                }
            } else {
                foreach ($plan as $migration) {
                    $version = $migration['version'];
                    if (isset($seen[$version])) {
                        continue;
                    }
                    $this->executeSql($migration['up']);
                    Models\MigrationRecord::using($this->database)->create(['namespace' => $namespace, 'version' => $version, 'checksum' => hash('sha256', $migration['up'] . "\0" . $migration['down']), 'batch' => $batch + 1, 'applied_at' => time()]);
                }
            }
        };
        // MySQL DDL 会隐式提交。将迁移器串行化，不将 DDL 视为事务操作。
        $name = 'loom-migrate-' . substr(hash('sha256', $this->database->query('SELECT DATABASE() AS name')[0]['name']), 0, 40);
        $locked = $this->database->query('SELECT GET_LOCK(?,30) AS acquired', [$name]);
        if ((int)$locked[0]['acquired'] !== 1) {
            throw new \RuntimeException('Cannot acquire migration lock');
        }
        try {
            $operation();
        } finally {
            $this->database->query('SELECT RELEASE_LOCK(?)', [$name]);
        }
    }
    /**
     * 按分号拆分并执行非空 SQL 片段；只适用于项目使用的简单迁移 SQL 格式。
     *
     * @param string $sql 以分号分隔的简单 SQL 脚本，不支持在字符串或过程体中嵌入分号的通用解析。
     * @return void 无返回值；SQL 执行错误向上传播。
     *
     * 内部调用示例（仅类内使用）：
     * @example $this->executeSql('CREATE TABLE example (id BIGINT PRIMARY KEY);');
     */
    private function executeSql(string $sql): void
    {
        foreach (explode(';', $sql) as $statement) {
            if (trim($statement) !== '') {
                $this->database->connection()->exec(trim($statement));
            }
        }
    }
    /**
     * 校验迁移历史并返回计划中各版本的应用状态。
     *
     * @param string $namespace 非空迁移命名空间；与已有迁移历史保持一致。
     * @param array $plan 按版本严格升序排列的迁移数组，每项含 version、up、down。
     * @return array 包含 version、applied、batch、applied_at 的列表。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $status = $migrations->status('studio', $plan);
     */
    public function status(string $namespace, array $plan): array
    {
        $this->ledger();
        $seen = $this->inspect($namespace, $plan);
        $status = [];
        foreach ($plan as $migration) {
            $row = $seen[$migration['version']] ?? null;
            $status[] = ['version' => $migration['version'], 'applied' => $row !== null, 'batch' => (int) ($row['batch'] ?? 0), 'applied_at' => (int) ($row['applied_at'] ?? 0)];
        }
        return $status;
    }
    /**
     * 依次应用框架内置的 loom_base 和 loom 命名空间迁移。
     *
     * 参数：无。
     *
     * @return void 无返回值，不包含应用自定义命名空间迁移。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $migrations->initialize();
     */
    public function initialize(): void
    {
        $this->run('loom_base', $this->files(dirname(__DIR__, 2) . '/resources/migrations/base'));
        $this->run('loom', $this->files(dirname(__DIR__, 2) . '/resources/migrations'));

    }
}
