<?php

declare(strict_types=1);

namespace App\Http\Controllers\Jobs;

use Loom\Request;
use Loom\Response;
use Loom\Models\Job;
use Loom\HttpError;

#[\Net\Annotation\Controller]
#[\Net\Annotation\Middleware(\App\Http\Middleware\RequireWorkspaceAccess::class)]
final class RetryJobController
{
    #[\Net\Annotation\PostMapping('/api/studio/jobs/{id}/retry')]
    public function retry(Request $request): Response
    {
        $user = $request->attribute('user');
        $affected = Job::whereId($request->parameter('id'))->whereOwner($user['id'])->whereState('failed')
            ->update(['state' => 'pending', 'attempts' => 0, 'available' => time(), 'error' => null]);
        if ($affected === 0) {
            throw new HttpError(409, '只能重试自己的失败任务');
        }
        return Response::json(['ok' => true], 202);
    }
}
