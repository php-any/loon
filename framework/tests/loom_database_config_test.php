<?php

declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';

$config = new \Loom\Config(dirname(__DIR__, 2));
$database = new \Loom\Database($config);
$previous = getenv('LOOM_DATABASE');
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
    $config = new \Loom\Config(dirname(__DIR__, 2));
    if ($config->get('database.dsn') !== $dsn) {
        throw new RuntimeException('MySQL DSN must be preserved without filesystem path resolution');
    }
    putenv('LOOM_DATABASE=mysql:host=changed;dbname=changed');
    if ($config->get('database.dsn') !== $dsn) {
        throw new RuntimeException('Environment values must be cached with PHP configuration');
    }
    $fresh = new \Loom\Config(dirname(__DIR__, 2));
    if ($fresh->get('database.dsn') !== 'mysql:host=changed;dbname=changed') {
        throw new RuntimeException('A new configuration must load the current environment');
    }
} finally {
    putenv($previous === false ? 'LOOM_DATABASE' : 'LOOM_DATABASE=' . $previous);
}
echo "loom_database_config_test: OK\n";
