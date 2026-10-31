<?php
declare(strict_types=1);

namespace App\Http\Controllers\Studio;

use Loom\Action;
use Loom\Request;
use Loom\Response;
use Loom\Database;
use App\Models\StudioTask;
use App\Models\StudioProject;

final class StateController implements Action
{
    public function __invoke(Request $request): Response
    {
        $user = $request->attribute('user'); $id = $user['id'];
        $tasks = StudioTask::query()->where('user_id', '=', $id);
        $projectId = $request->query('project')[0] ?? '';
        if ($projectId !== '') { $project = StudioProject::query()->where('id', '=', $projectId)->where('user_id', '=', $id)->firstOrFail(); $tasks = $project->tasks(); }
        return Response::json([
            'user' => $user,
            'tasks' => $tasks->orderBy('id', 'DESC')->rows(),
            'projects' => StudioProject::query()->where('user_id', '=', $id)->orderBy('id', 'DESC')->rows(),
            'migrations' => Database::query("SELECT version,batch,applied_at FROM loom_migrations WHERE namespace='studio' ORDER BY version"),
            'jobs' => Database::query('SELECT id,kind,state,attempts,available,created FROM loom_jobs WHERE owner=? ORDER BY id DESC LIMIT 12', [$id]),
            'reports' => Database::query('SELECT id,total,completed,created FROM studio_reports WHERE user_id=? ORDER BY id DESC LIMIT 6', [$id]),
            'schedules' => Database::query('SELECT name,interval_seconds,next_run,expression,timezone FROM loom_schedules WHERE owner=?', [$id]),
        ])->withHeader('Cache-Control', 'no-store');
    }
}
