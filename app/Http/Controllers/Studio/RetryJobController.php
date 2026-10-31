<?php
declare(strict_types=1);

namespace App\Http\Controllers\Studio;

use Loom\Action;
use Loom\Request;
use Loom\Response;
use Loom\Auth;
use Loom\Database;
use Loom\HttpError;

final class RetryJobController implements Action
{
    public function __invoke(Request $request): Response
    {
        $user = Auth::guard(); $result = Database::execute("UPDATE loom_jobs SET state='pending',attempts=0,available=unixepoch(),error=NULL WHERE id=? AND owner=? AND state='failed'", [$request->parameter('id'), $user['id']]);
        if ($result['affected'] === 0) { throw new HttpError(409, '只能重试自己的失败任务'); }
        return Response::json(['ok' => true], 202);
    }
}
