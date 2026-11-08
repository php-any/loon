<?php

declare(strict_types=1);
require dirname(__DIR__) . '/framework/bootstrap.php';
$tests = array_merge(glob(dirname(__DIR__) . '/framework/tests/*_test.php'), glob(__DIR__ . '/integration/*_test.php'));
sort($tests, SORT_STRING);
if (getenv('LOOM_TEST_MYSQL') !== '1') {
    throw new LogicException('Run scripts/test-storage.ps1 -Suites scripts with disposable MySQL and Redis services');
}
$app = (static function () { return require dirname(__DIR__) . '/loom.php'; })();
try {
    $failures = $app->services->withScope(static function (\Loom\ServiceScope $scope) use ($tests): array {
        $database = $scope->get(\Loom\Database::class);
        $process = $scope->get(\Loom\Process::class);
        $dsn = $database->pdoDsn();
        $prefix = getenv('LOOM_REDIS_PREFIX') ?: 'loom-test:';
        $failures = [];
        foreach ($tests as $test) {
            $name = 'loom_test_' . bin2hex(random_bytes(10));
            $database->execute('CREATE DATABASE ' . $name . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_bin');
            putenv('LOOM_DATABASE=' . preg_replace('/dbname=[^;]+/', 'dbname=' . $name, $dsn));
            putenv('LOOM_REDIS_PREFIX=' . $prefix . $name . ':');
            try {
                $result = $process->run([PHP_BINARY, $test], 120);
                echo $result['stdout'];
                if ($result['stderr'] !== '') {
                    echo $result['stderr'];
                }
                if ($result['code'] !== 0 || $result['stderr'] !== '' || !str_contains($result['stdout'], ': OK')) {
                    $failures[] = basename($test);
                }
            } catch (Throwable $error) {
                echo $error->getMessage(), "\n";
                $failures[] = basename($test);
            } finally {
                putenv('LOOM_DATABASE=' . $dsn);
                putenv('LOOM_REDIS_PREFIX=' . $prefix);
                $database->execute('DROP DATABASE ' . $name);
            }
        }
        return $failures;
    });
} finally {
    $app->dispose();
}
if ($failures !== []) {
    echo 'FAILED: ' . implode(', ', $failures), "\n";
    exit(1);
}
echo count($tests), " script suites passed\n";
