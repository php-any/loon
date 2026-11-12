<?php

declare(strict_types=1);

namespace App\Http\Controllers\Projects;

use Loom\Request;
use Loom\Response;
use App\Models\Projects\Task;

#[\Loom\Attributes\Controller]
final class UpdateTaskController
{
    #[\Loom\Attributes\Route('PATCH', '/api/studio/tasks/{id}', 'studio.task', [\App\Http\Middleware\RequireWorkspaceAccess::class, \Loom\Http\Middleware\ParseJsonInput::class])]
    public function update(Request $request): Response
    {
        $user = $request->attribute('user');
        $input = $request->attribute('input')->only(['completed']);
        $task = Task::whereId($request->parameter('id'))->whereUserId($user['id'])->firstOrFail();
        $task->completed = $input->boolean('completed') ? 1 : 0;
        Task::whereUserId($task->user_id)->whereId($task->id)->update(['completed' => $task->completed]);
        return Response::json(['ok' => true]);
    }
}
