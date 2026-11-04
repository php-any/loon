<?php

declare(strict_types=1);

namespace App\Http\Controllers\Release;

use App\Services\Release\ReleaseWorkspace as ReleaseDemo;
use App\Models\Projects\Project;
use App\Models\Projects\Task;
use App\Models\Projects\Report;
use App\Models\Release\ReleaseSession;
use Loom\Models\User;
use Loom\Models\Job;
use Loom\Models\ScheduledJob;
use Loom\Database;
use Loom\HttpError;
use Loom\Request;
use Loom\Response;
use Loom\Queue;
use Loom\Schedule;

#[\Loom\Attributes\Controller]
final class ReleaseController
{
    private \App\Services\Release\ReleaseWorkspace $releaseDemo;
    private \Loom\Database $database;
    private \Loom\Schedule $schedule;
    private \Loom\Queue $queue;

    public function __construct(\App\Services\Release\ReleaseWorkspace $releaseDemo, \Loom\Database $database, \Loom\Schedule $schedule, \Loom\Queue $queue)
    {
        $this->releaseDemo = $releaseDemo;
        $this->database = $database;
        $this->schedule = $schedule;
        $this->queue = $queue;
    }

    #[\Loom\Attributes\Route('GET', '/api/demo/release')]
    public function state(Request $request): Response
    {
        return Response::json($this->releaseDemo->state($this->releaseDemo->session($request)))->withHeader('Cache-Control', 'no-store');
    }

    #[\Loom\Attributes\Route('POST', '/api/demo/release/start')]
    public function start(Request $request): Response
    {
        ReleaseDemo::origin($request);
        try {
            return $this->state($request);
        } catch (HttpError $error) {
            if ($error->status !== 401) {
                throw $error;
            }
        }
        $token = bin2hex(random_bytes(32));
        $csrf = bin2hex(random_bytes(32));
        $expires = time() + 7200;
        // 发布验收场景使用独立的身份和 cookie。
        $owner = $this->database->transaction(function () use ($token, $csrf, $expires): int {
            $owner = User::create(['name' => '发布体验空间', 'email' => bin2hex(random_bytes(16)) . '@demo.invalid', 'password' => '!demo-session-only'])->id;
            ReleaseSession::create(['token' => hash('sha256', $token), 'owner' => $owner, 'csrf' => $csrf, 'created' => time(), 'expires' => $expires]);
            $this->releaseDemo->event($owner, 'workspace', '独立体验空间已创建，有效期两小时');
            return $owner;
        });
        return Response::json($this->releaseDemo->state(['owner' => $owner, 'csrf' => $csrf, 'expires' => $expires]), 201)
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('Set-Cookie', 'loom_demo=' . $token . '; Path=/api/demo; Max-Age=7200; HttpOnly; SameSite=Lax' . (str_starts_with($request->origin(), 'https://') ? '; Secure' : ''));
    }

    #[\Loom\Attributes\Route('POST', '/api/demo/release/project')]
    public function project(Request $request): Response
    {
        $session = $this->releaseDemo->guard($request);
        $name = trim($request->json()->only(['name'])->string('name', 1, 100));
        if ($name === '') {
            throw new HttpError(422, '请输入发布项目名称');
        }
        $this->database->transaction(function () use ($session, $name): void {
            $owner = $session['owner'];
            if (Project::whereUserId($owner)->count() !== 0) {
                throw new HttpError(409, '当前空间已有发布项目');
            }
            $project = Project::create(['user_id' => $owner, 'name' => $name]);
            foreach (['确认需求与发布范围', '完成接口验收', '核对上线说明'] as $title) {
                Task::create(['user_id' => $owner, 'project_id' => $project->id, 'title' => $title]);
            }
            $this->releaseDemo->event($owner, 'project', '创建项目「' . $name . '」与 3 项发布验收任务');
        });
        return $this->state($request);
    }

    #[\Loom\Attributes\Route('PATCH', '/api/demo/release/tasks/{id}')]
    public function task(Request $request): Response
    {
        $session = $this->releaseDemo->guard($request);
        $done = $request->json()->only(['completed'])->boolean('completed');
        $this->database->transaction(function () use ($session, $request, $done): void {
            $task = Task::whereUserId($session['owner'])->whereId($request->parameter('id'))->firstOrFail();
            if ((int)$task->completed === (int)$done) {
                return;
            }
            $task->completed = $done ? 1 : 0;
            Task::whereUserId($task->user_id)->whereId($task->id)->update(['completed' => $task->completed]);
            $this->releaseDemo->event($session['owner'], 'task', ($done ? '完成：' : '重新打开：') . $task->title);
        });
        return $this->state($request);
    }

    #[\Loom\Attributes\Route('POST', '/api/demo/release/report')]
    public function report(Request $request): Response
    {
        $session = $this->releaseDemo->guard($request);
        $owner = $session['owner'];
        $this->database->transaction(function () use ($owner): void {
            if (Task::whereUserId($owner)->count() === 0 || Task::whereUserId($owner)->where('completed', '=', 0)->count() !== 0) {
                throw new HttpError(409, '请先完成全部验收任务');
            }
            if (Job::whereOwner($owner)->whereIn('state', ['pending', 'running'])->exists()) {
                throw new HttpError(409, '已有摘要正在等待或执行');
            }
            $id = $this->queue->dispatch('demo.release.digest', [], $owner, 5);
            $this->releaseDemo->event($owner, 'queued', '发布摘要 #' . $id . ' 已提交，5 秒后可被后台领取');
        });
        return $this->state($request);
    }

    #[\Loom\Attributes\Route('POST', '/api/demo/release/schedule')]
    public function schedule(Request $request): Response
    {
        $session = $this->releaseDemo->guard($request);
        $enabled = $request->json()->only(['enabled'])->boolean('enabled');
        $owner = $session['owner'];
        if ($enabled && !Report::whereUserId($owner)->exists()) {
            throw new HttpError(409, '请先生成一份发布摘要');
        }
        $this->database->transaction(function () use ($owner, $enabled): void {
            if ($enabled) {
                $this->schedule->every('demo.release.' . $owner, 15, 'demo.release.digest', [], $owner);
            } else {
                ScheduledJob::whereName('demo.release.' . $owner)->whereOwner($owner)->delete();
            }
            $this->releaseDemo->event($owner, 'schedule', $enabled ? '启用每 15 秒自动回顾（体验加速节奏）' : '自动回顾已暂停');
        });
        return $this->state($request);
    }
}
