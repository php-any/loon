<?php

declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';

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
$app = (static function () { return require dirname(__DIR__, 2) . '/loom.php'; })();
try {
    $app->services->withScope(static function (\Loom\ServiceScope $scope): void {
        $database = $scope->get(\Loom\Database::class);
        $literal = 'rowid key unixepoch() excluded.value ON CONFLICT(id) DO NOTHING';
        storageCheck($database->query("SELECT 'rowid key unixepoch() excluded.value ON CONFLICT(id) DO NOTHING' AS value")[0]['value'] === $literal, 'Raw SQL literals must be unchanged');
        $database->execute('CREATE TABLE storage_probe(id BIGINT PRIMARY KEY AUTO_INCREMENT,name VARCHAR(255) UNIQUE,value TEXT)');
        $sql = 'INSERT INTO storage_probe(name,value) VALUES(?,?)';
        $database->execute($sql . $database->conflict(['name']), ['one', 'first']);
        storageCheck($database->execute($sql . $database->conflict(['name']), ['one', 'ignored'])['affected'] === 0, 'Conflict ignore');
        $database->execute($sql . $database->conflict(['name'], ['value']), ['one', 'updated']);
        storageCheck($database->query('SELECT value FROM storage_probe')[0]['value'] === 'updated', 'Structured upsert');
        try {
            $database->transaction(function () use ($sql, $scope): void {
                $scope->get(\Loom\Database::class)->execute($sql, ['rollback', 'value']);
                throw new RuntimeException('expected');
            });
        } catch (RuntimeException $e) {
            storageCheck($e->getMessage() === 'expected', 'Original transaction error');
        }
        storageCheck(count($database->query('SELECT id FROM storage_probe')) === 1, 'Transaction rollback');
        $nested = false;
        try {
            $database->transaction(function () use ($scope, &$nested): void {
                try {
                    $scope->get(\Loom\Database::class)->transaction(fn () => null);
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
            $database->conflict(['name); DELETE FROM storage_probe']);
        } catch (LogicException $e) {
            $blocked = true;
        }
        storageCheck($blocked, 'Conflict identifiers validated');
        storageCheck(extension_loaded('redis') && class_exists('Redis'), 'Origami Redis module is explicitly loaded');
    });
    echo "loom_storage_test: OK\n";
} finally {
    $app->dispose();
}
