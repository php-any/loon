<?php

declare(strict_types=1);
require dirname(__DIR__, 2) . '/framework/bootstrap.php';

function checkCli(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
$app = (static function () { return require dirname(__DIR__, 2) . '/loom.php'; })();
try {
    $process = $app->services->get(\Loom\Process::class);
    $fixture = tempnam(sys_get_temp_dir(), 'loom-cli-');
    try {
        file_put_contents($fixture, '<?php echo $argv[1]; fwrite(fopen("php://stderr","w"),"expected error"); exit(7);');
        $result = $process->run([PHP_BINARY, $fixture, 'literal $() & argument']);
        checkCli($result['code'] === 7 && $result['stdout'] === 'literal $() & argument' && $result['stderr'] === 'expected error', 'argv and exit streams');
        file_put_contents($fixture, '<?php echo str_repeat("a",262144); fwrite(fopen("php://stderr","w"),str_repeat("b",262144));');
        $result = $process->run([PHP_BINARY, $fixture], 5);
        checkCli($result['code'] === 0 && strlen($result['stdout']) === 262144 && strlen($result['stderr']) === 262144, 'drain both output pipes without deadlock');
        file_put_contents($fixture, '<?php echo str_repeat("x",4194305);');
        $overflow = false;
        try {
            $process->run([PHP_BINARY, $fixture], 5);
        } catch (RuntimeException $error) {
            $overflow = str_contains($error->getMessage(), '4 MiB');
        }
        checkCli($overflow, 'child output limit');
        file_put_contents($fixture, '<?php sleep(5);');
        $started = microtime(true);
        $timedOut = false;
        try {
            $process->run([PHP_BINARY, $fixture], 1);
        } catch (RuntimeException $error) {
            $timedOut = str_contains($error->getMessage(), 'timed out');
        }
        checkCli($timedOut && microtime(true) - $started < 4, 'child deadline');
    } finally {
        unlink($fixture);
    }
    $entry = $app->configuration->root() . '/loom.php';
    $result = $process->run([PHP_BINARY, $entry, 'list']);
    checkCli($result['code'] === 0 && substr_count($result['stdout'], '  serve  ') === 1 && !str_contains($result['stdout'], '_job'), 'one registry, hidden worker command');
    $result = $process->run([PHP_BINARY, $entry, 'help']);
    checkCli($result['code'] === 0 && str_contains($result['stdout'], 'Loom Console') && substr_count($result['stdout'], '  serve  ') === 1 && !str_contains($result['stdout'], '_job'), 'bare help lists full catalog');
    foreach (['serve', 'tcp:serve', 'grpc:serve', 'migrate:up', 'migrate:down', 'migrate:status', 'queue:work', 'schedule:work'] as $name) {
        $result = $process->run([PHP_BINARY, $entry, 'help', $name]);
        checkCli($result['code'] === 0 && str_contains($result['stdout'], '用法：' . $name), 'full built-in help: ' . $name);
        $result = $process->run([PHP_BINARY, $entry, $name, '--unknown']);
        checkCli($result['code'] === 2, 'uniform input error code: ' . $name);
    }
    $result = $process->run([PHP_BINARY, $entry, 'doctor']);
    checkCli($result['code'] === 0 && str_contains($result['stdout'], 'Origami std: OK'), 'doctor');
    // 迁移与 worker 使用测试运行器为本脚本创建的独立数据库。
    foreach ([['migrate:up'], ['migrate:status'], ['schedule:work', '--once'], ['queue:work', '--once']] as $args) {
        $result = $process->run(array_merge([PHP_BINARY, $entry], $args));
        checkCli($result['code'] === 0, 'built-in execution ' . implode(' ', $args) . ': ' . $result['stderr']);
    }
    // 内置命令也能在进程内通过 Application::run 执行。
    checkCli($app->run(['doctor']) === 0, 'programmatic built-in execution');
    // 用独立迁移命名空间验证回滚，避免依赖应用历史迁移的 SQL 方言。
    $migrationRoot = sys_get_temp_dir() . '/loom-cli-migration-' . bin2hex(random_bytes(6));
    mkdir($migrationRoot . '/config', 0700, true);
    mkdir($migrationRoot . '/migrations', 0700, true);
    file_put_contents($migrationRoot . '/config/modules.php', '<?php return [];');
    file_put_contents($migrationRoot . '/config/database.php', '<?php return require ' . var_export(dirname($entry) . '/config/database.php', true) . ';');
    file_put_contents($migrationRoot . '/config/migrations.php', '<?php return ["namespace" => "console_probe", "path" => "migrations"];');
    file_put_contents($migrationRoot . '/migrations/001_probe.up.sql', 'CREATE TABLE console_migration_probe(id BIGINT PRIMARY KEY);');
    file_put_contents($migrationRoot . '/migrations/001_probe.down.sql', 'DROP TABLE console_migration_probe;');
    $migrationApp = \Loom\Application::createBuilder($migrationRoot)->build();
    try {
        foreach (['migrate:up', 'migrate:status', 'migrate:down', 'migrate:up'] as $command) {
            checkCli($migrationApp->run([$command]) === 0, 'Programmatic migration: ' . $command);
        }
        $rows = $migrationApp->services->withScope(static fn (\Loom\ServiceScope $scope): array => $scope->get(\Loom\Database::class)->query('SELECT * FROM console_migration_probe'));
        checkCli(count($rows) === 0, 'Migration reapplied after rollback');
    } finally {
        $migrationApp->dispose();
        foreach (['config/modules.php', 'config/database.php', 'config/migrations.php', 'migrations/001_probe.up.sql', 'migrations/001_probe.down.sql'] as $file) {
            unlink($migrationRoot . '/' . $file);
        }
        rmdir($migrationRoot . '/config');
        rmdir($migrationRoot . '/migrations');
        rmdir($migrationRoot);
    }
    $result = $process->run([PHP_BINARY, $entry, 'serve', '--unknown']);
    checkCli($result['code'] !== 0, 'reject ignored options');
    $result = $process->run([PHP_BINARY, $entry, 'grpc:serve', '--unknown']);
    checkCli($result['code'] !== 0, 'reject ignored gRPC options');
    $name = 'GeneratedProbe' . bin2hex(random_bytes(6));
    $signature = 'probe:' . strtolower($name);
    $path = $app->configuration->root() . '/app/Console/Commands/' . $name . '.php';
    checkCli(!file_exists($path), 'fresh generator path');
    try {
        $result = $process->run([PHP_BINARY, $entry, 'make:command', $name, '--command=' . $signature]);
        checkCli($result['code'] === 0 && is_file($path), 'generate command');
        $original = file_get_contents($path);
        $result = $process->run([PHP_BINARY, $entry, $signature]);
        checkCli($result['code'] === 0 && str_contains($result['stdout'], '命令已就绪'), 'generated command executes');
        $result = $process->run([PHP_BINARY, $entry, 'make:command', $name, '--command=' . $signature]);
        checkCli($result['code'] !== 0 && file_get_contents($path) === $original, 'generator preserves existing source');
    } finally {
        if (is_file($path)) {
            unlink($path);
        }
    }
} finally {
    $app->dispose();
}
echo "loom_cli_test: OK\n";
