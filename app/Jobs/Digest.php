<?php
declare(strict_types=1);

namespace App\Jobs;

use Loom\Database;
use Loom\Request;
use Loom\Response;

final class Digest
{
    public function handle(Request $request): Response
    {
        $job = json_decode($request->body(), true, 64, JSON_THROW_ON_ERROR);
        if ($job['kind'] !== 'studio.digest') { throw new \LogicException('Unknown job kind'); }
        // 使用幂等插入，重试不会产生重复的业务记录。
        Database::execute('INSERT INTO studio_reports(user_id,job_id,total,completed) SELECT ?,?,COUNT(*),COALESCE(SUM(completed),0) FROM studio_tasks WHERE user_id=? ON CONFLICT(job_id) DO NOTHING', [$job['owner'], $job['id'], $job['owner']]);
        return Response::json(['report' => 'ready', 'job' => $job['id']]);
    }
}
