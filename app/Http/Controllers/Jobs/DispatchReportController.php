<?php

declare(strict_types=1);

namespace App\Http\Controllers\Jobs;

use Loom\Action;
use Loom\Request;
use Loom\Response;
use Loom\Queue;

#[\Loom\Attributes\Controller]
#[\Loom\Attributes\Route('POST', '/api/studio/reports', '', [\App\Http\Middleware\RequireWorkspaceAccess::class, \Loom\Http\Middleware\ParseJsonInput::class])]
final class DispatchReportController implements Action
{
    private \Loom\Queue $queue;

    public function __construct(\Loom\Queue $queue)
    {
        $this->queue = $queue;
    }

    public function __invoke(Request $request): Response
    {
        $user = $request->attribute('user');
        $input = $request->attribute('input')->only(['delay']);
        $id = $this->queue->dispatch('studio.digest', [], $user['id'], $input->integer('delay', 0, 30));
        return Response::json(['id' => $id], 202);
    }
}
