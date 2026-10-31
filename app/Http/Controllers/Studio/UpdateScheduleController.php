<?php
declare(strict_types=1);

namespace App\Http\Controllers\Studio;

use Loom\Action;
use Loom\Request;
use Loom\Response;
use Loom\Auth;
use Loom\Database;
use Loom\Schedule;

final class UpdateScheduleController implements Action
{
    public function __invoke(Request $request): Response
    {
        $user = Auth::guard(); $input = $request->json()->only(['enabled']);
        if ($input->boolean('enabled')) { Schedule::every('studio.digest.' . $user['id'], 60, 'studio.digest', [], $user['id']); }
        else { Database::execute('DELETE FROM loom_schedules WHERE name=? AND owner=?', ['studio.digest.' . $user['id'], $user['id']]); }
        return Response::json(['ok' => true]);
    }
}
