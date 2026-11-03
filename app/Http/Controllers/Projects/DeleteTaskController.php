<?php

declare(strict_types=1);

namespace App\Http\Controllers\Projects;

use Loom\Action;
use Loom\Database;
use Loom\Request;
use Loom\Response;
use App\Models\Projects\Task;

#[\Loom\Attributes\Controller]
#[\Loom\Attributes\Route('DELETE', '/api/studio/tasks/{id}', '', [\App\Http\Middleware\RequireWorkspaceAccess::class])]
final class DeleteTaskController implements Action
{
    public function __construct(private Database $database)
    {
    }

    public function __invoke(Request $request): Response
    {
        $user = $request->attribute('user');
        $task = Task::using($this->database)->whereId($request->parameter('id'))->whereUserId($user['id'])->firstOrFail();
        Task::using($this->database)->whereUserId($task->user_id)->whereId($task->id)->delete();
        return Response::json(['ok' => true]);
    }
}
