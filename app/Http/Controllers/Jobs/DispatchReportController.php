<?php

declare(strict_types=1);

namespace App\Http\Controllers\Jobs;

use Loom\Request;
use Loom\Response;

#[\Net\Annotation\Controller]
#[\Net\Annotation\Middleware(\App\Http\Middleware\RequireWorkspaceAccess::class)]
#[\Net\Annotation\Middleware(\Loom\Http\Middleware\ParseJsonInput::class)]
final class DispatchReportController
{
    private \Loom\Queue $queue;

    public function __construct(\Loom\Queue $queue)
    {
        $this->queue = $queue;
    }

    #[\Net\Annotation\PostMapping('/api/studio/reports')]
    public function report(Request $request): Response
    {
        $user = $request->attribute('user');
        $input = $request->attribute('input')->only(['delay']);
        $id = $this->queue->dispatch('studio.digest', [], $user['id'], $input->integer('delay', 0, 30));
        return Response::json(['id' => $id], 202);
    }
}
