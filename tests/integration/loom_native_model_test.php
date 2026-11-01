<?php

declare(strict_types=1);
$runtimeScope = (require dirname(__DIR__, 2) . '/framework/bootstrap/autoload.php')(dirname(__DIR__, 2));
$runtimeServices = $runtimeScope->services();
require dirname(__DIR__) . '/fixtures/native-model.php';
use NativeModelRegression\Entity;

function checkNativeModel(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
$database = $runtimeServices->get(\Loom\Database::class);
$database->connect($runtimeServices->get(\Loom\Config::class)->get('database.dsn'));
$query = Entity::query();
try {
    $database->execute('CREATE TABLE native_model_probe(probe_id BIGINT PRIMARY KEY AUTO_INCREMENT,display_name VARCHAR(255) NOT NULL,score DOUBLE NOT NULL,enabled INTEGER NOT NULL,note TEXT)');
    $entity = new Entity('first', 1.5, true);
    checkNativeModel($entity->name === 'first' && $entity->id === null, 'Models can be constructed without a connection');
    $insert = $query->insert($entity);
    $stored = $query->findOrFail($insert->insertId);
    checkNativeModel(is_int($stored->id) && $stored->score === 1.5 && $stored->enabled === true, 'Native hydration restores scalar types and bypasses required constructor');
    $stored->rename('second');
    $query->whereKey($stored->id)->update(['name' => $stored->name]);
    checkNativeModel($query->first()->name === 'second', 'Property aliases support explicit updates');
    $query->whereKey($stored->id)->update(['name' => 'third', 'score' => 2.5, 'enabled' => false, 'note' => 'memo']);
    $stored = $query->findOrFail($stored->id);
    checkNativeModel($stored->score === 2.5 && $stored->enabled === false && $stored->note === 'memo', 'False and scalar values persist');
    checkNativeModel($stored->toArray()['display_name'] === 'third', 'Serialization uses column names');
    $query->whereKey($stored->id)->update(['note' => null]);
    checkNativeModel($query->first()->note === null, 'Explicit null is preserved');
    $database->execute('UPDATE native_model_probe SET note=? WHERE probe_id=?', ['external note', $stored->id]);
    $query->whereKey($stored->id)->update(['name' => 'property update']);
    checkNativeModel($query->first()->note === 'external note', 'Explicit updates preserve untouched database columns');
    $conflicting = new Entity('conflict update', 3.0, false);
    $conflicting->id = $stored->id;
    $query->insert($conflicting, ['id'], ['name', 'enabled']);
    checkNativeModel($query->first()->name === 'conflict update' && $query->first()->enabled === false && $query->first()->score === 2.5, 'Conflict target and update properties resolve column aliases and preserve unrelated fields');
    $conflicting->name = 'ignored';
    $query->insert($conflicting, ['id']);
    checkNativeModel($query->count() === 1 && $query->first()->name === 'conflict update', 'Ignore conflict resolves aliased primary key');
    $query->whereKey($stored->id)->update(['name' => 'property update']);
    $rejected = false;
    try {
        $query->whereKey($stored->id)->update(['unknown' => 'value']);
    } catch (LogicException $error) {
        $rejected = true;
    }
    checkNativeModel($rejected, 'Unknown columns are rejected');
    $invalid = new Entity('invalid', 1.0, true);
    unset($invalid->name);
    $rejected = false;
    try {
        $query->insert($invalid);
    } catch (\Throwable $error) {
        $rejected = true;
    }
    checkNativeModel($rejected && $query->count() === 1, 'Database constraints reject missing required values without adding a row');
    $rejected = false;
    try {
        $query->whereKey($stored->id)->update(['name' => null]);
    } catch (\Throwable $error) {
        $rejected = true;
    }
    checkNativeModel($rejected && $query->first()->name === 'property update', 'Non-null constraints preserve the existing row on failure');
    $explicit = new Entity('explicit', 2.0, true);
    $explicit->id = 99;
    $query->insert($explicit);
    $query->whereKey(99)->update(['id' => 100]);
    $query->whereKey(100)->update(['name' => 'after key change']);
    checkNativeModel($query->find(99) === null && $query->findOrFail(100)->name === 'after key change', 'Mapped primary key updates');
    $query->whereKey(100)->delete();
    $query->whereKey($stored->id)->delete();
    checkNativeModel($query->count() === 0, 'Deletion uses mapped primary keys');
    $rejected = false;
    try {
        $query->findOrFail($stored->id);
    } catch (\Loom\HttpError $error) {
        $rejected = $error->status === 404;
    }
    checkNativeModel($rejected, 'Missing records fail explicitly');
} finally {
    $runtimeScope->close();
}
echo "loom_native_model_test: OK\n";
