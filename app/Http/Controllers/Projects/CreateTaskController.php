<?php

declare(strict_types=1);

namespace App\Http\Controllers\Projects;

use Loom\Action;
use Loom\Request;
use Loom\Response;
use Loom\HttpError;
use App\Models\Projects\Task;
use App\Models\Projects\Project;

#[\Loom\Attributes\Controller]
#[\Loom\Attributes\Route('POST', '/api/studio/tasks', '', [\App\Http\Middleware\RequireWorkspaceAccess::class, \Loom\Http\Middleware\ParseJsonInput::class])]
final class CreateTaskController implements Action
{
    public function __invoke(Request $request): Response
    {
        $user = $request->attribute('user');
        $input = $request->attribute('input')->only(['title', 'project_id']);
        $title = trim($input->string('title', 1, 240));
        if ($title === '') {
            throw new HttpError(422, '请写下一件想完成的事');
        }
        $projectId = null;
        if ($input->has('project_id')) {
            $projectId = $input->integer('project_id', 1);
            Project::whereId($projectId)->whereUserId($user['id'])->firstOrFail();
        }
        $task = Task::create(['user_id' => $user['id'], 'title' => $title, 'project_id' => $projectId]);
        return Response::json($task->toArray(), 201);
    }
}
