<?php

declare(strict_types=1);
$boot = require dirname(__DIR__, 2) . '/framework/bootstrap/autoload.php';
$configRoot = sys_get_temp_dir() . '/loom-model-config-' . bin2hex(random_bytes(6));
mkdir($configRoot . '/config', 0700, true);
file_put_contents($configRoot . '/config/database.php', '<?php return ["dsn" => getenv("LOOM_DATABASE") ?: "mysql:host=127.0.0.1;dbname=loom_test"];');
$scope = new \Loom\ServiceScope(new \Loom\Config($configRoot));

#[\Database\Annotation\Table('model_scope_records')]
final class ScopedConvenientRecord extends \Loom\Model
{
    #[\Database\Annotation\Id]
    public int $id;
    public string $name;
    public int $owner;
    public ?string $note = null;
}

function checkModelScope(bool $ok, string $message): void
{
    if (!$ok) {
        throw new LogicException($message);
    }
}

$other = null;
try {
    $database = $scope->services()->get(\Loom\Database::class);
    $database->execute('CREATE TEMPORARY TABLE model_scope_records(id BIGINT PRIMARY KEY AUTO_INCREMENT, name TEXT NOT NULL, owner INTEGER NOT NULL, note TEXT)');
    $record = ScopedConvenientRecord::create(['name' => 'A', 'owner' => 1]);
    checkModelScope($record->name === 'A', 'framework config connection is available without manual ORM registration');
    checkModelScope(is_int($record->id) && is_int($record->owner), 'native hydration retains declared scalar types');
    checkModelScope(ScopedConvenientRecord::whereOwner(2)->whereName('A')->count() === 0, 'chained conditions preserve ownership');
    ScopedConvenientRecord::whereId($record->id)->update(['note' => 'memo']);
    ScopedConvenientRecord::whereId($record->id)->update(['note' => null]);
    checkModelScope(ScopedConvenientRecord::find($record->id)->note === null, 'explicit null update');
    ScopedConvenientRecord::upsert(['id' => $record->id, 'name' => 'A', 'owner' => 1, 'note' => 'upsert'], ['id'], ['note']);
    ScopedConvenientRecord::insertOrIgnore(['id' => $record->id, 'name' => 'ignored', 'owner' => 2], ['id']);
    checkModelScope(ScopedConvenientRecord::count() === 1 && ScopedConvenientRecord::find($record->id)->note === 'upsert', 'Static conflict writes use automatic framework connection');
    try {
        $database->transaction(function (): void {
            ScopedConvenientRecord::whereName('A')->update(['name' => 'changed']);
            ScopedConvenientRecord::upsert(['id' => 1, 'name' => 'changed', 'owner' => 1, 'note' => 'rolled back'], ['id'], ['note']);
            ScopedConvenientRecord::create(['name' => 'rolled back', 'owner' => 1]);
            throw new RuntimeException('rollback');
        });
    } catch (RuntimeException $error) {
        if ($error->getMessage() !== 'rollback') {
            throw $error;
        }
    }
    checkModelScope(ScopedConvenientRecord::count() === 1 && ScopedConvenientRecord::find($record->id)->name === 'A', 'model writes share PDO rollback');
    checkModelScope(ScopedConvenientRecord::find($record->id)->note === 'upsert', 'Static upsert shares transaction rollback');
    $captured = ScopedConvenientRecord::whereName('A');
    $unused = ScopedConvenientRecord::whereName('A');
    $other = new \Loom\ServiceScope(new \Loom\Config($configRoot));
    $second = $other->services()->get(\Loom\Database::class);
    $second->execute('CREATE TEMPORARY TABLE model_scope_records(id BIGINT PRIMARY KEY AUTO_INCREMENT, name TEXT NOT NULL, owner INTEGER NOT NULL, note TEXT)');
    $ambiguous = false;
    try {
        ScopedConvenientRecord::first();
    } catch (LogicException $error) {
        $ambiguous = str_contains($error->getMessage(), 'Multiple service scopes');
    }
    checkModelScope($ambiguous, 'opening another scope does not choose a database');
    ScopedConvenientRecord::using($second)->create(['name' => 'B', 'owner' => 2]);
    checkModelScope($captured->first()->name === 'A', 'query captures the original connection');
    checkModelScope(ScopedConvenientRecord::using($database)->first()->name === 'A', 'explicit database selects its own scope');
    checkModelScope(ScopedConvenientRecord::using($second)->first()->name === 'B', 'the other database stays on its own scope');
    $finished = new \Channel(2);
    foreach ([[$database, 'A'], [$second, 'B']] as $case) {
        \spawn(function () use ($case, $finished): void {
            try {
                for ($i = 0; $i < 10; $i++) {
                    usleep(1000);
                    checkModelScope(ScopedConvenientRecord::using($case[0])->first()->name === $case[1], 'concurrent model scopes are isolated');
                }
                $finished->send('ok');
            } catch (Throwable $error) {
                $finished->send($error->getMessage());
            }
        });
    }
    checkModelScope($finished->receive() === 'ok' && $finished->receive() === 'ok', 'concurrent scope checks');
    $other->close();
    checkModelScope(ScopedConvenientRecord::first()->name === 'A', 'closing another scope preserves this connection');
    $scope->close();
    $closed = false;
    try {
        $captured->first();
    } catch (Throwable $error) {
        $closed = true;
    }
    checkModelScope($closed, 'escaped query cannot reopen a closed scope');
    $closed = false;
    try {
        $unused->count();
    } catch (Throwable $error) {
        $closed = true;
    }
    checkModelScope($closed, 'an unexecuted query cannot fall back to another connection after close');
} finally {
    if ($other !== null) {
        $other->close();
    }
    $scope->close();
    unlink($configRoot . '/config/database.php');
    rmdir($configRoot . '/config');
    rmdir($configRoot);
}
echo "loom_model_scope_test: OK\n";
