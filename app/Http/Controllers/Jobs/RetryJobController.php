<?php

declare(strict_types=1);

namespace App\Http\Controllers\Jobs;

use Loom\Action;
use Loom\Database;
use Loom\Request;
use Loom\Response;
use Loom\Models\Job;
use Loom\HttpError;

#[\Loom\Attributes\Controller]
#[\Loom\Attributes\Route('POST', '/api/studio/jobs/{id}/retry', '', [\App\Http\Middleware\RequireWorkspaceAccess::class])]
final class RetryJobController implements Action
{
    public function __construct(private Database $database)
    {
    }

    public function __invoke(Request $request): Response
    {
        $user = $request->attribute('user');
        $affected = Job::using($this->database)->whereId($request->parameter('id'))->whereOwner($user['id'])->whereState('failed')
            ->update(['state' => 'pending', 'attempts' => 0, 'available' => time(), 'error' => null]);
        if ($affected === 0) {
            throw new HttpError(409, '只能重试自己的失败任务');
        }
        return Response::json(['ok' => true], 202);
    }
}
