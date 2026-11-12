<?php

declare(strict_types=1);

namespace App\Http\Controllers\Projects;

use Loom\Request;
use Loom\Response;
use App\Models\Projects\Task;

#[\Loom\Attributes\Controller]
final class DeleteTaskController
{
    #[\Loom\Attributes\Route('DELETE', '/api/studio/tasks/{id}', '', [\App\Http\Middleware\RequireWorkspaceAccess::class])]
    public function delete(Request $request): Response
    {
        $user = $request->attribute('user');
        $task = Task::whereId($request->parameter('id'))->whereUserId($user['id'])->firstOrFail();
        Task::whereUserId($task->user_id)->whereId($task->id)->delete();
        return Response::json(['ok' => true]);
    }
}
