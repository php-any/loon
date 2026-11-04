<?php

declare(strict_types=1);
$runtimeScope = (require dirname(__DIR__) . '/bootstrap/autoload.php')(dirname(__DIR__, 2));
$runtimeServices = $runtimeScope->container();
use Loom\Database;

/**
 * 断言测试条件成立，失败时立即抛出异常以终止当前测试。
 *
 * @param bool $ok 待断言的条件；为假时抛出测试失败异常。
 * @param string $message 断言失败时抛出的说明。
 * @return void 无返回值；条件失败时抛出 RuntimeException。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example storageCheck(true, '验证说明');
 */
function storageCheck(bool $ok, string $message): void
{
    if (!$ok) {
        throw new RuntimeException($message);
    }
}
$runtimeServices->get(\Loom\Database::class)->connect((string)getenv('LOOM_DATABASE'));
try {
    $literal = 'rowid key unixepoch() excluded.value ON CONFLICT(id) DO NOTHING';
    storageCheck($runtimeServices->get(\Loom\Database::class)->query("SELECT 'rowid key unixepoch() excluded.value ON CONFLICT(id) DO NOTHING' AS value")[0]['value'] === $literal, 'Raw SQL literals must be unchanged');
    $runtimeServices->get(\Loom\Database::class)->execute('CREATE TABLE storage_probe(id BIGINT PRIMARY KEY AUTO_INCREMENT,name VARCHAR(255) UNIQUE,value TEXT)');
    $sql = 'INSERT INTO storage_probe(name,value) VALUES(?,?)';
    $runtimeServices->get(\Loom\Database::class)->execute($sql . $runtimeServices->get(\Loom\Database::class)->conflict(['name']), ['one', 'first']);
    storageCheck($runtimeServices->get(\Loom\Database::class)->execute($sql . $runtimeServices->get(\Loom\Database::class)->conflict(['name']), ['one', 'ignored'])['affected'] === 0, 'Conflict ignore');
    $runtimeServices->get(\Loom\Database::class)->execute($sql . $runtimeServices->get(\Loom\Database::class)->conflict(['name'], ['value']), ['one', 'updated']);
    storageCheck($runtimeServices->get(\Loom\Database::class)->query('SELECT value FROM storage_probe')[0]['value'] === 'updated', 'Structured upsert');
    try {
        $runtimeServices->get(\Loom\Database::class)->transaction(function () use ($sql, $runtimeServices): void {
            $runtimeServices->get(\Loom\Database::class)->execute($sql, ['rollback', 'value']);
            throw new RuntimeException('expected');
        });
    } catch (RuntimeException $e) {
        storageCheck($e->getMessage() === 'expected', 'Original transaction error');
    }
    storageCheck(count($runtimeServices->get(\Loom\Database::class)->query('SELECT id FROM storage_probe')) === 1, 'Transaction rollback');
    $nested = false;
    try {
        $runtimeServices->get(\Loom\Database::class)->transaction(function () use ($runtimeServices, &$nested): void {
            try {
                $runtimeServices->get(\Loom\Database::class)->transaction(fn () => null);
            } catch (LogicException $e) {
                $nested = str_contains($e->getMessage(), 'Nested transactions');
            }
            if (!$nested) {
                throw new RuntimeException('nested transaction was accepted');
            }
        });
    } catch (RuntimeException $e) {
        storageCheck(false, $e->getMessage());
    }
    storageCheck($nested, 'Nested transaction is rejected without ending the outer transaction');
    $blocked = false;
    try {
        $runtimeServices->get(\Loom\Database::class)->conflict(['name); DELETE FROM storage_probe']);
    } catch (LogicException $e) {
        $blocked = true;
    }
    storageCheck($blocked, 'Conflict identifiers validated');
    storageCheck(extension_loaded('redis') && class_exists('Redis'), 'Origami Redis module is explicitly loaded');
    echo "loom_storage_test: OK\n";
} finally {
    $runtimeServices->get(\Loom\Database::class)->close();
}
