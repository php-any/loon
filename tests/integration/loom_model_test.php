<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/framework/bootstrap.php';

#[\Database\Annotation\Table('model_users')]
class ConvenientUser extends \Loom\Model
{
    #[\Database\Annotation\Id]
    public int $id;
    #[\Database\Annotation\Column('display_name', false)]
    public string $name;
    public int $team_id;
    public ?string $note;

    public static function query(): \Loom\ModelQuery
    {
        return static::on('model_test');
    }
}

#[\Database\Annotation\Table('other_users')]
class OtherConvenientUser extends \Loom\Model
{
    #[\Database\Annotation\Id]
    public int $id;
    #[\Database\Annotation\Column('display_name', false)]
    public string $name;
    public int $team_id;
    public ?string $note;

    public static function query(): \Loom\ModelQuery
    {
        return static::on('model_test');
    }
}

function checkModel(bool $condition, string $message): void
{
    if (!$condition) {
        throw new LogicException($message);
    }
}

$database = new \Loom\Database(new \Loom\Config(dirname(__DIR__, 2)));
$database->connect((string)getenv('LOOM_DATABASE'));
$database->register('model_test');
try {
    $native = \Database\DB::connection('model_test');
    $native->execute('CREATE TABLE model_users(id BIGINT PRIMARY KEY AUTO_INCREMENT, display_name TEXT NOT NULL, team_id INTEGER NOT NULL, note TEXT)');
    $native->execute('CREATE TABLE other_users(id BIGINT PRIMARY KEY AUTO_INCREMENT, display_name TEXT NOT NULL, team_id INTEGER NOT NULL, note TEXT)');
    $native->execute('INSERT INTO model_users VALUES(1,?,7,NULL),(2,?,9,?)', ['TEST', "' OR 1=1 --", 'memo']);
    $native->execute('INSERT INTO other_users VALUES(3,?,7,NULL)', ['OTHER']);

    $query = ConvenientUser::whereName('TEST');
    checkModel($query->native() instanceof \Database\DB, 'convenience methods return the native builder');
    $user = $query->first();
    checkModel($user instanceof ConvenientUser && $user->name === 'TEST', 'dynamic static method maps property aliases and hydrates native models');
    checkModel(count(ConvenientUser::whereTeamId(7)->get()) === 1, 'dynamic snake case field');
    checkModel(ConvenientUser::whereDisplayName('TEST')->first()->id === 1, 'dynamic column alias');
    checkModel(ConvenientUser::whereName("' OR 1=1 --")->first()->id === 2, 'values are bound');
    checkModel(count(ConvenientUser::whereNote(null)->get()) === 1, 'null equality');
    checkModel(ConvenientUser::where('note', '!=', null)->first()->id === 2, 'null inequality');
    checkModel(ConvenientUser::where('team_id', '>', 7)->first()->id === 2, 'explicit operators');
    checkModel(ConvenientUser::find(1)->name === 'TEST' && ConvenientUser::find(99) === null, 'find');
    checkModel(OtherConvenientUser::whereName('OTHER')->first()->id === 3, 'late static binding uses the concrete table');
    checkModel(count(ConvenientUser::all()) === 2 && count(OtherConvenientUser::all()) === 1, 'model queries are independent');
    checkModel(ConvenientUser::orderBy('id DESC')->limit(1)->first()->id === 2, 'native static forwarding and chaining');
    checkModel(ConvenientUser::on('model_test')->first() instanceof ConvenientUser, 'explicit named connection');
    $new = new ConvenientUser();
    $new->id = 4;
    $new->name = 'INSERTED';
    $new->team_id = 7;
    $new->note = 'native insert';
    checkModel(ConvenientUser::insert($new)->success && ConvenientUser::find(4)->name === 'INSERTED', 'insert delegates to native ORM');
    $ordered = ConvenientUser::orderBy('team_id');
    checkModel($ordered->orderBy('id', 'DESC')->pluck('id') === [4, 1, 2], 'secondary ordering preserves primary ordering');
    checkModel($ordered->orderBy('id')->pluck('id') === [1, 4, 2], 'ordering branches are immutable');
    $rejected = 0;
    foreach (['whereMissing', 'whereNameAndTeamId', 'missingMethod'] as $method) {
        try {
            ConvenientUser::{$method}('TEST');
        } catch (\InvalidArgumentException $error) {
            $rejected++;
        } catch (\BadMethodCallException $error) {
            $rejected++;
        }
    }
    try {
        ConvenientUser::whereName('TEST', 'extra');
    } catch (\InvalidArgumentException $error) {
        $rejected++;
    }
    try {
        ConvenientUser::where('name; DROP TABLE model_users', 'TEST');
    } catch (\InvalidArgumentException $error) {
        $rejected++;
    }
    try {
        ConvenientUser::findOrFail(99);
    } catch (\Loom\HttpError $error) {
        if ($error->status === 404) {
            $rejected++;
        }
    }
    checkModel($rejected === 6, 'invalid methods, arguments, identifiers and missing records are rejected');
} finally {
    \Database\removeConnection('model_test');
    $database->close();
}

echo "loom_model_test: OK\n";
