<?php
declare(strict_types=1);

namespace App\Http\Controllers\Studio;

use Loom\Action;
use Loom\Request;
use Loom\Response;
use App\Models\StudioTask;

final class UpdateTaskController implements Action
{
    public function __invoke(Request $request): Response
    {
        $user = $request->attribute('user'); $input = $request->attribute('input')->only(['completed']);
        $task = StudioTask::query()->where('id', '=', $request->parameter('id'))->where('user_id', '=', $user['id'])->firstOrFail();
        $task->set('completed', $input->boolean('completed') ? 1 : 0)->save();
        return Response::json(['ok' => true]);
    }
}
