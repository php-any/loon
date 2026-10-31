<?php
declare(strict_types=1);

namespace App\Http\Controllers\Studio;

use Loom\Action;
use Loom\Request;
use Loom\Response;
use Loom\Auth;
use Loom\Schedule;

final class SaveCronController implements Action
{
    public function __invoke(Request $request): Response
    {
        $user = Auth::guard(); $input = $request->json()->only(['expression', 'timezone']);
        Schedule::cron('studio.digest.' . $user['id'], $input->string('expression', 1, 100), $input->string('timezone', 1, 80), 'studio.digest', [], $user['id']);
        return Response::json(['ok' => true]);
    }
}
