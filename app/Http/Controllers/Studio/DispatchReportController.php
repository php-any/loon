<?php
declare(strict_types=1);

namespace App\Http\Controllers\Studio;

use Loom\Action;
use Loom\Request;
use Loom\Response;
use Loom\Queue;

final class DispatchReportController implements Action
{
    public function __invoke(Request $request): Response
    {
        $user = $request->attribute('user'); $input = $request->attribute('input')->only(['delay']);
        $id = Queue::dispatch('studio.digest', [], $user['id'], $input->integer('delay', 0, 30));
        return Response::json(['id' => $id], 202);
    }
}
