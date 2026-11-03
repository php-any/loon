<?php

declare(strict_types=1);

namespace App\Http\Controllers\Projects;

use Loom\Action;
use Loom\Database;
use Loom\Request;
use Loom\Response;
use App\Models\Projects\Task;
use App\Models\Projects\Project;
use App\Models\Projects\Report;
use Loom\Models\MigrationRecord;
use Loom\Models\Job;
use Loom\Models\ScheduledJob;

#[\Loom\Attributes\Controller]
#[\Loom\Attributes\Route('GET', '/api/studio/state', 'studio.state', [\App\Http\Middleware\RequireWorkspaceAccess::class])]
final class StateController implements Action
{
    private \App\Repositories\Projects\ProjectRepository $projects;
    private Database $database;

    public function __construct(\App\Repositories\Projects\ProjectRepository $projects, Database $database)
    {
        $this->projects = $projects;
        $this->database = $database;
    }

    public function __invoke(Request $request): Response
    {
        $user = $request->attribute('user');
        $id = $user['id'];
        $tasks = Task::using($this->database)->whereUserId($id);
        $projectId = $request->query('project')[0] ?? '';
        if ($projectId !== '') {
            $project = Project::using($this->database)->whereId($projectId)->whereUserId($id)->firstOrFail();
            $tasks = $this->projects->tasksFor($project);
        }
        return Response::json([
            'user' => $user,
            'tasks' => $tasks->orderBy('id', 'DESC')->rows(),
            'projects' => Project::using($this->database)->whereUserId($id)->orderBy('id', 'DESC')->rows(),
            'migrations' => MigrationRecord::using($this->database)->whereNamespace('studio')->orderBy('version')->rows(['version', 'batch', 'applied_at']),
            'jobs' => Job::using($this->database)->whereOwner($id)->orderBy('id', 'DESC')->limit(12)->rows(['id', 'kind', 'state', 'attempts', 'available', 'created']),
            'reports' => Report::using($this->database)->whereUserId($id)->orderBy('id', 'DESC')->limit(6)->rows(['id', 'total', 'completed', 'created']),
            'schedules' => ScheduledJob::using($this->database)->whereOwner($id)->rows(['name', 'interval_seconds', 'next_run', 'expression', 'timezone']),
        ])->withHeader('Cache-Control', 'no-store');
    }
}
