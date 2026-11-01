<?php

declare(strict_types=1);
$runtimeScope = (require dirname(__DIR__, 2) . '/framework/bootstrap/autoload.php')(dirname(__DIR__, 2));
$runtimeServices = $runtimeScope->services();
use Loom\Process;
use Loom\Config;

function checkCli(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
$fixture = tempnam(sys_get_temp_dir(), 'loom-cli-');
try {
    file_put_contents($fixture, '<?php echo $argv[1]; fwrite(fopen("php://stderr","w"),"expected error"); exit(7);');
    $result = $runtimeServices->get(\Loom\Process::class)->run([PHP_BINARY, $fixture, 'literal $() & argument']);
    checkCli($result['code'] === 7 && $result['stdout'] === 'literal $() & argument' && $result['stderr'] === 'expected error', 'argv and exit streams');
    file_put_contents($fixture, '<?php echo str_repeat("a",262144); fwrite(fopen("php://stderr","w"),str_repeat("b",262144));');
    $result = $runtimeServices->get(\Loom\Process::class)->run([PHP_BINARY, $fixture], 5);
    checkCli($result['code'] === 0 && strlen($result['stdout']) === 262144 && strlen($result['stderr']) === 262144, 'drain both output pipes without deadlock');
    file_put_contents($fixture, '<?php echo str_repeat("x",4194305);');
    $overflow = false;
    try {
        $runtimeServices->get(\Loom\Process::class)->run([PHP_BINARY, $fixture], 5);
    } catch (RuntimeException $error) {
        $overflow = str_contains($error->getMessage(), '4 MiB');
    }
    checkCli($overflow, 'child output limit');
    file_put_contents($fixture, '<?php sleep(5);');
    $started = microtime(true);
    $timedOut = false;
    try {
        $runtimeServices->get(\Loom\Process::class)->run([PHP_BINARY, $fixture], 1);
    } catch (RuntimeException $error) {
        $timedOut = str_contains($error->getMessage(), 'timed out');
    }
    checkCli($timedOut && microtime(true) - $started < 4, 'child deadline');
} finally {
    unlink($fixture);
}
$entry = $runtimeServices->get(\Loom\Config::class)->root() . '/loom.php';
$result = $runtimeServices->get(\Loom\Process::class)->run([PHP_BINARY, $entry, 'list']);
checkCli($result['code'] === 0 && substr_count($result['stdout'], '  serve  ') === 1 && !str_contains($result['stdout'], '_job'), 'one registry, hidden worker command');
foreach (['serve', 'tcp:serve', 'grpc:serve', 'migrate:up', 'migrate:down', 'migrate:status', 'queue:work', 'schedule:work'] as $name) {
    $result = $runtimeServices->get(\Loom\Process::class)->run([PHP_BINARY, $entry, 'help', $name]);
    checkCli($result['code'] === 0 && str_contains($result['stdout'], '用法：' . $name), 'full built-in help: ' . $name);
    $result = $runtimeServices->get(\Loom\Process::class)->run([PHP_BINARY, $entry, $name, '--unknown']);
    checkCli($result['code'] === 2, 'uniform input error code: ' . $name);
}
$result = $runtimeServices->get(\Loom\Process::class)->run([PHP_BINARY, $entry, 'doctor']);
checkCli($result['code'] === 0 && str_contains($result['stdout'], 'Origami std: OK'), 'doctor');
// 迁移与 worker 使用测试运行器为本脚本创建的独立数据库。
foreach ([['migrate:up'], ['migrate:status'], ['schedule:work', '--once'], ['queue:work', '--once']] as $args) {
    $result = $runtimeServices->get(\Loom\Process::class)->run(array_merge([PHP_BINARY, $entry], $args));
    checkCli($result['code'] === 0, 'built-in execution ' . implode(' ', $args) . ': ' . $result['stderr']);
}
// 内置命令也能通过 Console::call 执行。
$kernel = $runtimeServices->get(\Loom\Console\ConsoleKernel::class);
checkCli($kernel->console()->call(['doctor']) === 0, 'programmatic built-in execution');
// 用独立迁移命名空间验证回滚，避免依赖应用历史迁移的 SQL 方言。
$migrationRoot = sys_get_temp_dir() . '/loom-cli-migration-' . bin2hex(random_bytes(6));
mkdir($migrationRoot . '/config', 0700, true);
mkdir($migrationRoot . '/migrations', 0700, true);
file_put_contents($migrationRoot . '/config/database.php', '<?php return require ' . var_export(dirname($entry) . '/config/database.php', true) . ';');
file_put_contents($migrationRoot . '/config/migrations.php', '<?php return ["namespace" => "console_probe", "path" => "migrations"];');
file_put_contents($migrationRoot . '/migrations/001_probe.up.sql', 'CREATE TABLE console_migration_probe(id BIGINT PRIMARY KEY);');
file_put_contents($migrationRoot . '/migrations/001_probe.down.sql', 'DROP TABLE console_migration_probe;');
$migrationScope = new \Loom\ServiceScope(new \Loom\Config($migrationRoot));
try {
    $migrationConsole = $migrationScope->services()->get(\Loom\Console\ConsoleKernel::class)->console();
    foreach (['migrate:up', 'migrate:status', 'migrate:down', 'migrate:up'] as $command) {
        checkCli($migrationConsole->call([$command]) === 0, 'Programmatic migration: ' . $command);
    }
    checkCli(count($migrationScope->services()->get(\Loom\Database::class)->query('SELECT * FROM console_migration_probe')) === 0, 'Migration reapplied after rollback');
} finally {
    $migrationScope->close();
    foreach (['config/database.php', 'config/migrations.php', 'migrations/001_probe.up.sql', 'migrations/001_probe.down.sql'] as $file) {
        unlink($migrationRoot . '/' . $file);
    }
    rmdir($migrationRoot . '/config');
    rmdir($migrationRoot . '/migrations');
    rmdir($migrationRoot);
}
$result = $runtimeServices->get(\Loom\Process::class)->run([PHP_BINARY, $entry, 'serve', '--unknown']);
checkCli($result['code'] !== 0, 'reject ignored options');
$result = $runtimeServices->get(\Loom\Process::class)->run([PHP_BINARY, $entry, 'grpc:serve', '--unknown']);
checkCli($result['code'] !== 0, 'reject ignored gRPC options');
$name = 'GeneratedProbe' . bin2hex(random_bytes(6));
$signature = 'probe:' . strtolower($name);
$path = $runtimeServices->get(\Loom\Config::class)->root() . '/app/Console/Commands/' . $name . '.php';
checkCli(!file_exists($path), 'fresh generator path');
try {
    $result = $runtimeServices->get(\Loom\Process::class)->run([PHP_BINARY, $entry, 'make:command', $name, '--command=' . $signature]);
    checkCli($result['code'] === 0 && is_file($path), 'generate command');
    $original = file_get_contents($path);
    $result = $runtimeServices->get(\Loom\Process::class)->run([PHP_BINARY, $entry, $signature]);
    checkCli($result['code'] === 0 && str_contains($result['stdout'], '命令已就绪'), 'generated command executes');
    $result = $runtimeServices->get(\Loom\Process::class)->run([PHP_BINARY, $entry, 'make:command', $name, '--command=' . $signature]);
    checkCli($result['code'] !== 0 && file_get_contents($path) === $original, 'generator preserves existing source');
} finally {
    if (is_file($path)) {
        unlink($path);
    }
}
echo "loom_cli_test: OK\n";
