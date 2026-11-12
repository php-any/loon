<?php

declare(strict_types=1);

namespace App\Http\Controllers\Scheduling;

use Loom\Request;
use Loom\Response;
use Loom\Schedule;

#[\Net\Annotation\Controller]
#[\Net\Annotation\Middleware(\App\Http\Middleware\RequireWorkspaceAccess::class)]
#[\Net\Annotation\Middleware(\Loom\Http\Middleware\ParseJsonInput::class)]
final class PreviewCronController
{
    #[\Net\Annotation\PostMapping('/api/studio/cron/preview')]
    public function preview(Request $request): Response
    {
        $input = $request->attribute('input')->only(['expression', 'timezone']);
        return Response::json(['times' => Schedule::preview($input->string('expression', 1, 100), $input->string('timezone', 1, 80))]);
    }
}
