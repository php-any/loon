<?php

declare(strict_types=1);

namespace App\Http\Controllers\Projects;

use Loom\Request;
use Loom\Response;
use App\Models\Projects\Task;

#[\Net\Annotation\Controller]
#[\Net\Annotation\Middleware(\App\Http\Middleware\RequireWorkspaceAccess::class)]
final class DeleteTaskController
{
    #[\Net\Annotation\DeleteMapping('/api/studio/tasks/{id}')]
    public function delete(Request $request): Response
    {
        $user = $request->attribute('user');
        $task = Task::whereId($request->parameter('id'))->whereUserId($user['id'])->firstOrFail();
        Task::whereUserId($task->user_id)->whereId($task->id)->delete();
        return Response::json(['ok' => true]);
    }
}
