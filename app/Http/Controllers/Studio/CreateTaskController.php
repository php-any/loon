<?php
declare(strict_types=1);

namespace App\Http\Controllers\Studio;

use Loom\Action;
use Loom\Request;
use Loom\Response;
use Loom\Auth;
use Loom\HttpError;
use App\Models\StudioTask;
use App\Models\StudioProject;

final class CreateTaskController implements Action
{
    public function __invoke(Request $request): Response
    {
        $user = Auth::guard(); $input = $request->json()->only(['title', 'project_id']); $title = trim($input->string('title', 1, 240));
        if ($title === '') { throw new HttpError(422, '请写下一件想完成的事'); }
        $projectId = null;
        if ($input->has('project_id')) { $projectId = $input->integer('project_id', 1); StudioProject::query()->where('id', '=', $projectId)->where('user_id', '=', $user['id'])->firstOrFail(); }
        $task = StudioTask::create(['user_id' => $user['id'], 'title' => $title, 'project_id' => $projectId]);
        return Response::json($task->toArray(), 201);
    }
}
