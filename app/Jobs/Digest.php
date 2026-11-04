<?php

declare(strict_types=1);

namespace App\Jobs;

use Loom\Database;
use App\Models\Projects\Task;
use App\Models\Projects\Report;
use Loom\Request;
use Loom\Response;

#[\Loom\Attributes\Job('studio.digest')]
final class Digest
{
    private \Loom\Database $database;

    public function __construct(\Loom\Database $database)
    {
        $this->database = $database;
    }

    public function handle(Request $request): Response
    {
        $job = json_decode($request->body(), true, 64, JSON_THROW_ON_ERROR);
        if ($job['kind'] !== 'studio.digest') {
            throw new \LogicException('Unknown job kind');
        }
        $this->database->transaction(function () use ($job): void {
            $counts = Task::whereUserId($job['owner'])->aggregate(['total' => ['COUNT', '*'], 'completed' => ['SUM', 'completed']]);
            Report::insertOrIgnore(['user_id' => $job['owner'], 'job_id' => $job['id'], 'total' => (int)$counts['total'], 'completed' => (int)$counts['completed']], ['job_id']);
        });
        return Response::json(['report' => 'ready', 'job' => $job['id']]);
    }
}
