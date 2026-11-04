<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Release\ReleaseWorkspace as ReleaseDemo;
use App\Services\Release\ReleaseProtocols;
use Loom\Database;
use App\Models\Release\ReleaseSession;
use App\Models\Release\ReleaseTicket;
use App\Models\Projects\Report;
use Loom\Models\ScheduledJob;
use Loom\Request;
use Loom\Response;

#[\Loom\Attributes\Job('demo.release.digest')]
final class ReleaseDigest
{
    private \Loom\Database $database;
    private \App\Services\Release\ReleaseProtocols $releaseProtocols;
    private \App\Services\Release\ReleaseWorkspace $releaseDemo;

    public function __construct(\Loom\Database $database, \App\Services\Release\ReleaseProtocols $releaseProtocols, \App\Services\Release\ReleaseWorkspace $releaseDemo)
    {
        $this->database = $database;
        $this->releaseProtocols = $releaseProtocols;
        $this->releaseDemo = $releaseDemo;
    }

    public function handle(Request $request): Response
    {
        $job = json_decode($request->body(), true, 64, JSON_THROW_ON_ERROR);
        $owner = (int)$job['owner'];
        $session = ReleaseSession::whereOwner($owner)->rows(['expires']);
        if ($session === [] || (int)$session[0]['expires'] <= time()) {
            ScheduledJob::whereName('demo.release.' . $owner)->whereOwner($owner)->delete();
            return Response::json(['skipped' => '体验空间已过期']);
        }
        if (Report::whereJobId($job['id'])->exists()) {
            return Response::json(['report' => 'ready', 'job' => $job['id']]);
        }
        $token = bin2hex(random_bytes(32));
        ReleaseTicket::upsert(['job_id' => $job['id'], 'token' => hash('sha256', $token), 'expires' => time() + 30], ['job_id'], ['token', 'expires']);
        try {
            // 网络调用期间不持有数据库事务。
            $snapshot = $this->releaseProtocols->exchange($owner, (int)$job['id'], 'tcp', 'JSON line', ['action' => 'release.snapshot', 'job' => (int)$job['id'], 'token' => $token]);
        } finally {
            ReleaseTicket::whereJobId($job['id'])->delete();
        }
        $counts = $snapshot['result'];
        $decision = $this->releaseProtocols->exchange($owner, (int)$job['id'], 'grpc', '/loom.examples.ReleasePolicy/Evaluate', ['total' => $counts['total'], 'completed' => $counts['completed']]);
        if (!$decision['ready']) {
            throw new \RuntimeException('gRPC 发布规则未通过：' . $decision['reason']);
        }
        return $this->database->transaction(function () use ($job, $owner, $counts): Response {
            $result = Report::insertOrIgnore(['user_id' => $owner, 'job_id' => $job['id'], 'total' => $counts['total'], 'completed' => $counts['completed']], ['job_id']);
            if ($result->rowsAffected > 0) {
                $this->releaseDemo->event($owner, 'report', '后台任务 #' . $job['id'] . ' 已生成发布摘要');
            }
            return Response::json(['report' => 'ready', 'job' => $job['id']]);
        });
    }
}
