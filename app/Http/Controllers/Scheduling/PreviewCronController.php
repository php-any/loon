<?php

declare(strict_types=1);

namespace App\Http\Controllers\Scheduling;

use Loom\Request;
use Loom\Response;
use Loom\Schedule;

#[\Loom\Attributes\Controller]
final class PreviewCronController
{
    #[\Loom\Attributes\Route('POST', '/api/studio/cron/preview', '', [\App\Http\Middleware\RequireWorkspaceAccess::class, \Loom\Http\Middleware\ParseJsonInput::class])]
    public function preview(Request $request): Response
    {
        $input = $request->attribute('input')->only(['expression', 'timezone']);
        return Response::json(['times' => Schedule::preview($input->string('expression', 1, 100), $input->string('timezone', 1, 80))]);
    }
}
