<?php

use App\Models\Projects\Task;
use App\Models\Projects\Project;

$runtimeScope = (require dirname(__DIR__, 2) . '/framework/bootstrap/autoload.php')(dirname(__DIR__, 2));
$runtimeServices = $runtimeScope->container();
$runtimeServices->get(\Loom\Database::class)->connect($runtimeServices->get(\Loom\Database::class)->pdoDsn());
$runtimeScope->load('framework/bootstrap/database.php');
require dirname(__DIR__) . '/fixtures/remove_default_admin.php';
$studio = $runtimeServices->get(\App\Repositories\Projects\ProjectRepository::class);
function assertOrm($ok, $message)
{
    if (!$ok) {
        throw new LogicException($message);
    }
}
$runtimeServices->get(\Loom\Database::class)->execute("INSERT INTO loom_users(id,name,email,password) VALUES(1,'Ada','ada@example.test','unused')");
$project = new Project();
$project->user_id = 1;
$project->name = 'A';
$project = Project::create(['user_id' => $project->user_id, 'name' => $project->name]);
$base = Task::query()->where('user_id', '=', 1);
$first = new Task();
$first->user_id = 1;
$first->title = "' OR 1=1 --";
$first->project_id = $project->id;
$first = Task::create($first->toArray());
$second = new Task();
$second->user_id = 1;
$second->title = 'B';
$second = Task::create($second->toArray());
assertOrm(is_int($first->id), 'int cast');
assertOrm($base->count() === 2, 'count');
assertOrm($base->where('project_id', '=', null)->count() === 1 && $base->count() === 2, 'immutable/null query');
assertOrm($base->where('title', '=', "' OR 1=1 --")->count() === 1, 'bound parameter');
assertOrm($studio->tasksFor($project)->count() === 1, 'has many');
assertOrm($studio->projectFor($first)->name === 'A', 'belongs to');
assertOrm($base->orderBy('id', 'DESC')->limit(1)->first()->id === $second->id, 'order and limit');
$first->completed = 1;
Task::whereId($first->id)->update($first->toArray());
assertOrm($base->where('completed', '=', 1)->count() === 1, 'save');
$first->created = 123;
Task::whereId($first->id)->update($first->toArray());
assertOrm($base->where('id', '=', $first->id)->first()->created === 123, 'Previously unlisted fields can be assigned');
$base->where('id', '=', $first->id)->update(['created' => 456]);
assertOrm($base->where('id', '=', $first->id)->first()->created === 456, 'Bulk updates accept all mapped fields');
$rejected = 0;
try {
    $base->where('id; DROP TABLE studio_tasks', '=', 1);
} catch (LogicException $e) {
    $rejected++;
}
try {
    $base->where('id', 'OR 1=1', 1);
} catch (LogicException $e) {
    $rejected++;
}
try {
    $base->where('id', '=', $first->id)->update(['unknown' => 99]);
} catch (LogicException $e) {
    $rejected++;
}
try {
    Task::query()->delete();
} catch (LogicException $e) {
    $rejected++;
}
assertOrm($rejected === 4, 'guards');
Task::whereId($second->id)->delete();
assertOrm($base->count() === 1, 'delete');
$runtimeServices->get(\Loom\Database::class)->close();
echo "loom_orm_test: OK\n";
