<?php
use App\Models\StudioTask;
use App\Models\StudioProject;
require_once dirname(__DIR__, 2) . '/bootstrap/autoload.php';
function assertOrm($ok, $message) { if (!$ok) { throw new LogicException($message); } }
$project = StudioProject::create(['user_id' => 1, 'name' => 'A']);
$base = StudioTask::query()->where('user_id', '=', 1);
$first = StudioTask::create(['user_id' => 1, 'title' => "' OR 1=1 --", 'project_id' => $project->get('id')]);
$second = StudioTask::create(['user_id' => 1, 'title' => 'B', 'project_id' => null]);
assertOrm(is_int($first->get('id')), 'int cast');
assertOrm($base->count() === 2, 'count');
assertOrm($base->where('project_id', '=', null)->count() === 1 && $base->count() === 2, 'immutable/null query');
assertOrm($base->where('title', '=', "' OR 1=1 --")->count() === 1, 'bound parameter');
assertOrm($project->tasks()->count() === 1, 'has many');
assertOrm($first->project()->get('name') === 'A', 'belongs to');
assertOrm($base->orderBy('id', 'DESC')->limit(1)->first()->get('id') === $second->get('id'), 'order and limit');
$first->set('completed', 1)->save();
assertOrm($base->where('completed', '=', 1)->count() === 1, 'save');
$rejected = 0;
try { $base->where('id; DROP TABLE studio_tasks', '=', 1); } catch (LogicException $e) { $rejected++; }
try { $base->where('id', 'OR 1=1', 1); } catch (LogicException $e) { $rejected++; }
try { $first->set('id', 99); } catch (LogicException $e) { $rejected++; }
try { StudioTask::query()->delete(); } catch (LogicException $e) { $rejected++; }
assertOrm($rejected === 4, 'guards');
$second->delete(); assertOrm($base->count() === 1, 'delete');
return Loom\Response::json(['ok' => true])->toArray();
