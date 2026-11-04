<?php

declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';

class DatabaseNamedModel extends \Loom\Model
{
    protected static ?string $connection = 'reporting';

    public static function declaredConnection(): ?string
    {
        return static::$connection;
    }
}

class DatabaseDefaultModel extends \Loom\Model
{
    public static function declaredConnection(): ?string
    {
        return static::$connection;
    }
}

$config = new \Loom\Config(dirname(__DIR__, 2));
$database = new \Loom\Database($config);
$previous = getenv('LOOM_DATABASE');
$previousUrl = getenv('DB_URL');
try {
    foreach (['storage/database/test.db', ':memory:', 'pgsql:host=localhost;dbname=test'] as $dsn) {
        putenv('LOOM_DATABASE=' . $dsn);
        $rejected = false;
        try {
            $database->open($dsn);
        } catch (InvalidArgumentException $error) {
            $rejected = str_contains($error->getMessage(), 'MySQL DSN');
        }
        if (!$rejected) {
            throw new RuntimeException('Database must reject non-MySQL DSNs before connecting');
        }
    }
    $dsn = 'mysql:host=127.0.0.1;port=13306;dbname=test;charset=utf8mb4';
    putenv('LOOM_DATABASE=' . $dsn);
    putenv('DB_URL=mysql://other:secret@10.9.9.9:3309/other');
    $config = new \Loom\Config(dirname(__DIR__, 2));
    $mysql = $config->get('database.connections.mysql');
    $loaded = new \Loom\Database($config);
    if ($mysql['dsn'] !== $dsn || $loaded->pdoDsn() !== $dsn) {
        throw new RuntimeException('MySQL DSN must be preserved without filesystem path resolution');
    }
    putenv('LOOM_DATABASE=mysql:host=changed;dbname=changed');
    if ($config->get('database.connections.mysql.dsn') !== $dsn || $loaded->pdoDsn() !== $dsn) {
        throw new RuntimeException('Environment values must be cached with PHP configuration');
    }
    $fresh = new \Loom\Config(dirname(__DIR__, 2));
    $changed = $fresh->get('database.connections.mysql');
    if ($changed['dsn'] !== 'mysql:host=changed;dbname=changed' || (new \Loom\Database($fresh))->pdoDsn() !== $changed['dsn']) {
        throw new RuntimeException('A new configuration must load the current environment');
    }
    $expectedDefault = getenv('DB_CONNECTION');
    if ($expectedDefault === false || $expectedDefault === '') {
        $expectedDefault = getenv('LOOM_DB_CONNECTION');
    }
    if ($expectedDefault === false || $expectedDefault === '') {
        $expectedDefault = 'mysql';
    }
    if ($fresh->get('database.default') !== $expectedDefault) {
        throw new RuntimeException('Default connection name must follow DB_CONNECTION');
    }
    if ($fresh->get('database.connections.' . $fresh->get('database.default') . '.dsn') !== 'mysql:host=changed;dbname=changed') {
        throw new RuntimeException('Default connection DSN must follow LOOM_DATABASE');
    }
    $root = sys_get_temp_dir() . '/loom-database-' . bin2hex(random_bytes(6));
    $legacyRoot = sys_get_temp_dir() . '/loom-database-legacy-' . bin2hex(random_bytes(6));
    $previousUser = getenv('LOOM_MYSQL_USER');
    $previousPassword = getenv('LOOM_MYSQL_PASSWORD');
    try {
        mkdir($root . '/config', 0777, true);
        file_put_contents($root . '/config/database.php', <<<'PHP'
<?php
return [
    'default' => 'mysql',
    'dsn' => 'mysql:host=127.0.0.1;port=3306;dbname=primary;charset=utf8mb4',
    'connections' => [
        'mysql' => [
            'driver' => 'mysql',
            'dsn' => 'mysql:host=127.0.0.1;port=3306;dbname=primary;charset=utf8mb4',
            'host' => 'ignored.example',
            'username' => 'primary-user',
            'password' => 'primary-secret',
        ],
        'reporting' => [
            'driver' => 'mysql',
            'host' => '10.0.0.8',
            'port' => '3307',
            'database' => 'reports',
            'username' => 'report-user',
            'password' => 'report-secret',
            'charset' => 'utf8mb4',
        ],
        'socketed' => [
            'driver' => 'mysql',
            'unix_socket' => '/tmp/mysql.sock',
            'database' => 'sockdb',
            'username' => 'sock-user',
            'password' => 'sock-secret',
        ],
        'broken' => [
            'driver' => 'mysql',
            'unix_socket' => '/tmp/../mysql.sock',
            'database' => 'sockdb',
        ],
        'postgres' => [
            'driver' => 'pgsql',
            'host' => '127.0.0.1',
            'database' => 'reports',
        ],
        'analytics' => [
            'driver' => 'mysql',
            'url' => 'mysql://analytics-user:analytics-secret@10.1.0.9:3308/analytics?charset=utf8mb4',
            'host' => 'ignored.example',
            'port' => '1',
            'database' => 'ignored',
            'username' => 'ignored-user',
            'password' => 'ignored-secret',
        ],
    ],
];
PHP);
        $many = new \Loom\Database(new \Loom\Config($root));
        if ($many->name() !== 'mysql' || $many->connection() !== $many || $many->connection('mysql') !== $many) {
            throw new RuntimeException('The default connection must be stable on the scoped database');
        }
        $reports = $many->connection('reporting');
        if ($reports === $many || $reports->name() !== 'reporting' || $many->connection('reporting') !== $reports) {
            throw new RuntimeException('A named connection must stay unique inside the scope');
        }
        if ($reports->connection() !== $many || $reports->connection('mysql') !== $many) {
            throw new RuntimeException('Omitting the name must return the default connection');
        }
        $primary = $many->driverDsn();
        $reportDriver = $reports->driverDsn();
        if ($primary === $reportDriver || !str_contains($primary, 'primary-user:') || !str_contains($primary, '@tcp(127.0.0.1:3306)/primary') || str_contains($primary, 'ignored.example')) {
            throw new RuntimeException('An explicit DSN must select that MySQL database');
        }
        if (!str_contains($reportDriver, 'report-user:') || !str_contains($reportDriver, '@tcp(10.0.0.8:3307)/reports')) {
            throw new RuntimeException('Host settings must select the named MySQL database');
        }
        if (!str_contains($many->connection('socketed')->driverDsn(), '@unix(/tmp/mysql.sock)/sockdb')) {
            throw new RuntimeException('unix_socket must select that MySQL database');
        }
        $analytics = $many->connection('analytics')->driverDsn();
        if (!str_contains($analytics, 'analytics-user:') || !str_contains($analytics, '@tcp(10.1.0.9:3308)/analytics') || str_contains($analytics, 'ignored.example')) {
            throw new RuntimeException('Laravel url must override host, database, and username');
        }
        foreach (['missing' => 'Unknown database connection', 'postgres' => 'MySQL connection', 'broken' => 'MySQL DSN'] as $name => $message) {
            $rejected = false;
            try {
                $many->connection($name)->driverDsn();
            } catch (InvalidArgumentException $error) {
                $rejected = str_contains($error->getMessage(), $message);
            }
            if (!$rejected) {
                throw new RuntimeException('Connection ' . $name . ' must be rejected');
            }
        }
        mkdir($legacyRoot . '/config', 0777, true);
        file_put_contents($legacyRoot . '/config/database.php', '<?php return ["dsn" => "mysql:host=127.0.0.1;port=3306;dbname=legacy;charset=utf8mb4"];');
        putenv('LOOM_MYSQL_USER=legacy-user');
        putenv('LOOM_MYSQL_PASSWORD=legacy-secret');
        $legacy = new \Loom\Database(new \Loom\Config($legacyRoot));
        $legacyDriver = $legacy->driverDsn();
        if ($legacy->name() !== 'mysql' || !str_contains($legacyDriver, 'legacy-user:') || !str_contains($legacyDriver, '@tcp(127.0.0.1:3306)/legacy')) {
            throw new RuntimeException('A DSN-only configuration must remain the default MySQL connection');
        }
        if (DatabaseNamedModel::declaredConnection() !== 'reporting' || DatabaseDefaultModel::declaredConnection() !== null) {
            throw new RuntimeException('A model must keep its declared connection name');
        }
    } finally {
        putenv($previousUser === false ? 'LOOM_MYSQL_USER' : 'LOOM_MYSQL_USER=' . $previousUser);
        putenv($previousPassword === false ? 'LOOM_MYSQL_PASSWORD' : 'LOOM_MYSQL_PASSWORD=' . $previousPassword);
        foreach ([$root, $legacyRoot] as $directory) {
            if (!is_dir($directory . '/config')) {
                continue;
            }
            foreach (glob($directory . '/config/*') as $file) {
                unlink($file);
            }
            rmdir($directory . '/config');
            rmdir($directory);
        }
    }
} finally {
    putenv($previous === false ? 'LOOM_DATABASE' : 'LOOM_DATABASE=' . $previous);
    putenv($previousUrl === false ? 'DB_URL' : 'DB_URL=' . $previousUrl);
}
echo "loom_database_config_test: OK\n";
