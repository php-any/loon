<?php

declare(strict_types=1);

namespace App\Services\Release;

use Loom\Database;
use Loom\HttpError;
use Loom\Request;
use App\Models\Release\ReleaseSession;
use App\Models\Release\ReleaseEvent;
use App\Models\Release\ProtocolExchange;
use App\Models\Projects\Project;
use App\Models\Projects\Task;
use App\Models\Projects\Report;
use Loom\Models\Job;
use Loom\Models\ScheduledJob;

final class ReleaseWorkspace
{
    private \Loom\Database $database;
    private \Loom\RuntimeStatus $runtimeStatus;

    public function __construct(\Loom\Database $database, \Loom\RuntimeStatus $runtimeStatus)
    {
        $this->database = $database;
        $this->runtimeStatus = $runtimeStatus;
    }

    public function session(Request $request): array
    {
        $token = '';
        foreach (explode(';', $request->header('cookie')) as $cookie) {
            $parts = explode('=', trim($cookie), 2);
            if ($parts[0] === 'loom_demo') {
                $token = $parts[1] ?? '';
            }
        }
        $rows = ReleaseSession::using($this->database)->whereToken(hash('sha256', $token))->where('expires', '>', time())->rows(['owner', 'csrf', 'created', 'expires']);
        if ($rows === []) {
            throw new HttpError(401, '体验空间尚未创建或已经过期');
        }
        $session = $rows[0];
        $session['owner'] = (int)$session['owner'];
        return $session;
    }

    public function guard(Request $request): array
    {
        self::origin($request);
        $session = $this->session($request);
        if (!hash_equals($session['csrf'], $request->header('x-csrf-token'))) {
            throw new HttpError(403, '体验请求校验失败，请刷新页面');
        }
        return $session;
    }

    public static function origin(Request $request): void
    {
        if ($request->header('origin') !== $request->origin()) {
            throw new HttpError(403, '请求来源不匹配');
        }
    }

    public function event(int $owner, string $kind, string $detail): void
    {
        ReleaseEvent::using($this->database)->create(['owner' => $owner, 'kind' => $kind, 'detail' => $detail]);
    }

    public function state(array $session): array
    {
        $owner = $session['owner'];
        return [
            'workspace' => ['id' => $owner, 'expires' => (int)$session['expires']],
            'csrf' => $session['csrf'],
            'exchanges' => ProtocolExchange::using($this->database)->whereOwner($owner)->orderBy('id', 'DESC')->limit(20)->rows(['id', 'job_id', 'protocol', 'ok', 'request', 'response', 'elapsed_ms', 'created']),
            'project' => Project::using($this->database)->whereUserId($owner)->orderBy('id')->firstRow(['id', 'name', 'created']),
            'tasks' => Task::using($this->database)->whereUserId($owner)->orderBy('id')->rows(['id', 'title', 'completed', 'created']),
            'jobs' => Job::using($this->database)->whereOwner($owner)->orderBy('id', 'DESC')->limit(20)->rows(['id', 'kind', 'state', 'attempts', 'available', 'created', 'error']),
            'reports' => Report::using($this->database)->whereUserId($owner)->orderBy('id', 'DESC')->limit(10)->rows(['id', 'job_id', 'total', 'completed', 'created']),
            'schedule' => ScheduledJob::using($this->database)->whereOwner($owner)->firstRow(['name', 'interval_seconds', 'next_run']),
            'events' => ReleaseEvent::using($this->database)->whereOwner($owner)->orderBy('id', 'DESC')->limit(40)->rows(['id', 'kind', 'detail', 'created']),
            'services' => $this->runtimeStatus->snapshot(),
            'now' => time(),
        ];
    }
}
