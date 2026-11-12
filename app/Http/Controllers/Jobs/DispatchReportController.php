<?php

declare(strict_types=1);

namespace App\Http\Controllers\Jobs;

use Loom\Request;
use Loom\Response;

#[\Loom\Attributes\Controller]
final class DispatchReportController
{
    private \Loom\Queue $queue;

    public function __construct(\Loom\Queue $queue)
    {
        $this->queue = $queue;
    }

    #[\Loom\Attributes\Route('POST', '/api/studio/reports', '', [\App\Http\Middleware\RequireWorkspaceAccess::class, \Loom\Http\Middleware\ParseJsonInput::class])]
    public function report(Request $request): Response
    {
        $user = $request->attribute('user');
        $input = $request->attribute('input')->only(['delay']);
        $id = $this->queue->dispatch('studio.digest', [], $user['id'], $input->integer('delay', 0, 30));
        return Response::json(['id' => $id], 202);
    }
}
