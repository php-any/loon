<?php
declare(strict_types=1);

namespace App\Http\Controllers\Studio;

use Loom\Action;
use Loom\Request;
use Loom\Response;
use Loom\Auth;
use App\Models\StudioTask;

final class DeleteTaskController implements Action
{
    public function __invoke(Request $request): Response
    {
        $user = Auth::guard(); StudioTask::query()->where('id', '=', $request->parameter('id'))->where('user_id', '=', $user['id'])->firstOrFail()->delete();
        return Response::json(['ok' => true]);
    }
}
