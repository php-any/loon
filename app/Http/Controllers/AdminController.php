<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Access\Admin;
use Loom\Auth;
use Loom\Request;
use Loom\Response;
use App\Models\Identity\Account;
use App\Models\Projects\Project;
use App\Models\Projects\Task;

#[\Loom\Attributes\Controller]
final class AdminController
{
    private \Loom\Auth $auth;
    private \App\Services\Access\Admin $admin;
    private \Loom\RuntimeStatus $runtimeStatus;
    private \Loom\Config $config;

    public function __construct(\Loom\Auth $auth, \App\Services\Access\Admin $admin, \Loom\RuntimeStatus $runtimeStatus, \Loom\Config $config)
    {
        $this->auth = $auth;
        $this->admin = $admin;
        $this->runtimeStatus = $runtimeStatus;
        $this->config = $config;
    }

    #[\Loom\Attributes\Route('GET', '/admin')]
    public function page(Request $request): Response
    {
        $entry = $this->config->path('public/admin/index.html');
        if (!is_file($entry)) {
            return Response::html('后台前端尚未构建，请在 frontend/vben-admin 执行 pnpm install 和 pnpm --filter @vben/web-ele build。开发环境可运行 scripts/dev-vben.ps1。', 503);
        }
        // Vben 会注入初始主题和加载脚本。仅允许当前响应中的
        // 脚本，不允许任意内联 JavaScript。
        $nonce = bin2hex(random_bytes(24));
        $html = str_replace('<script', '<script nonce="' . $nonce . '"', file_get_contents($entry));
        return Response::html($html)
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('Content-Security-Policy', "default-src 'self'; script-src 'self' 'nonce-$nonce'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self' data:; connect-src 'self'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'");
    }

    #[\Loom\Attributes\Route('GET', '/api/admin/session')]
    public function session(Request $request): Response
    {
        $user = $this->auth->guard();
        $grants = $this->admin->grants($user['id']);
        $account = Account::whereUserId($user['id'])->rows(['verified_at']);
        $settings = $this->admin->settings();
        return Response::json(['user' => $user, 'permissions' => $grants, 'menus' => $this->admin->menus($grants), 'verified' => ($account[0]['verified_at'] ?? null) !== null, 'branding' => ['name' => $settings['site_name'], 'description' => $settings['site_description']]])->withHeader('Cache-Control', 'no-store');
    }

    #[\Loom\Attributes\Route('GET', '/api/admin/overview')]
    public function overview(Request $request): Response
    {
        $user = $this->auth->guard();
        $this->admin->require($user['id'], 'overview.view');
        return Response::json(['projects' => Project::whereUserId($user['id'])->count(), 'tasks' => Task::whereUserId($user['id'])->count(), 'completed' => Task::whereUserId($user['id'])->whereCompleted(1)->count(), 'runtime' => $this->runtimeStatus->snapshot()])->withHeader('Cache-Control', 'no-store');
    }

    #[\Loom\Attributes\Route('GET', '/api/admin/manage/{resource}')]
    public function listing(Request $request): Response
    {
        $user = $this->auth->guard();
        $page = max(1, min(100000, (int)($request->query('page')[0] ?? 1)));
        $search = substr($request->query('q')[0] ?? '', 0, 100);
        return Response::json($this->admin->listing($request->parameter('resource'), $user['id'], $page, $search))->withHeader('Cache-Control', 'no-store');
    }

    #[\Loom\Attributes\Route('POST', '/api/admin/manage/{resource}')]
    public function mutate(Request $request): Response
    {
        $user = $this->auth->guard();
        return Response::json($this->admin->mutate($request->parameter('resource'), $user['id'], $request->json()))->withHeader('Cache-Control', 'no-store');
    }
}
