<?php

declare(strict_types=1);

namespace App\Http\Controllers\Scheduling;

use Loom\Action;
use Loom\Request;
use Loom\Response;
use Loom\Schedule;

#[\Loom\Attributes\Controller]
#[\Loom\Attributes\Route('POST', '/api/studio/cron/preview', '', [\App\Http\Middleware\RequireWorkspaceAccess::class, \Loom\Http\Middleware\ParseJsonInput::class])]
final class PreviewCronController implements Action
{
    public function __invoke(Request $request): Response
    {
        $input = $request->attribute('input')->only(['expression', 'timezone']);
        return Response::json(['times' => Schedule::preview($input->string('expression', 1, 100), $input->string('timezone', 1, 80))]);
    }
}
