<?php

declare(strict_types=1);
$scope = (require dirname(__DIR__, 2) . '/framework/bootstrap/autoload.php')(dirname(__DIR__, 2));
$services = $scope->services();
$database = $services->get(\Loom\Database::class);
$database->connect($services->get(\Loom\Config::class)->get('database.dsn'));

function checkModelCoverage(bool $condition, string $message): void
{
    if (!$condition) {
        throw new LogicException($message);
    }
}

try {
    $scope->load('framework/bootstrap/database.php');
    $services->get(\Loom\ModuleRegistry::class)->configured();
    $models = [];
    $classes = array_merge(
        \Loom\ClassDiscovery::scan(dirname(__DIR__, 2) . '/app/Models', 'App\\Models'),
        \Loom\ClassDiscovery::scan(dirname(__DIR__, 2) . '/framework/src/Database/Models', 'Loom\\Models')
    );
    foreach ($classes as $class) {
        $reflection = new ReflectionClass($class);
        if (!$reflection->isSubclassOf(\Loom\Model::class) || !$reflection->isInstantiable()) {
            continue;
        }
        $table = \Loom\ModuleRegistry::attribute($reflection, \Database\Annotation\Table::class);
        if ($table === null) {
            continue;
        }
        checkModelCoverage(!isset($models[$table->name]), 'Duplicate model mapping for ' . $table->name);
        $models[$table->name] = $class;
    }
    $tables = $database->query("SELECT TABLE_NAME AS name FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE'");
    foreach ($tables as $table) {
        $name = $table['name'];
        checkModelCoverage(isset($models[$name]), 'Missing model for table ' . $name);
        $class = $models[$name];
        $columns = [];
        foreach ((new ReflectionClass($class))->getProperties() as $property) {
            if ($property->isPublic() && !$property->isStatic()) {
                $columns[] = $class::modelColumn($property->getName());
            }
        }
        $schema = [];
        $tableColumns = $database->query('SELECT COLUMN_NAME AS name FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?', [$name]);
        foreach ($tableColumns as $column) {
            $schema[] = $column['name'];
        }
        sort($columns);
        sort($schema);
        checkModelCoverage($columns === $schema, 'Model columns differ from table ' . $name);
        $class::query()->limit(1)->get();
    }
    checkModelCoverage(count($models) === count($tables), 'Every mapped model has a persistent table');
    $user = \Loom\Models\User::create(['name' => 'Coverage', 'email' => 'coverage@example.test', 'password' => 'unused']);
    $pivot = \App\Models\Access\UserRole::create(['user_id' => $user->id, 'role_id' => 2]);
    checkModelCoverage($pivot->user_id === $user->id && $pivot->role_id === 2, 'Composite-key create reloads the exact record');
    checkModelCoverage(\App\Models\Access\UserRole::find(['user_id' => $user->id, 'role_id' => 2]) !== null, 'Composite-key lookup');
    \App\Models\Access\UserRole::whereUserId($user->id)->whereRoleId(2)->delete();
    checkModelCoverage(\App\Models\Access\UserRole::whereUserId($user->id)->count() === 0, 'Composite-key delete');
    echo 'loom_model_coverage_test: OK (' . count($tables) . " tables)\n";
} finally {
    $scope->close();
}
