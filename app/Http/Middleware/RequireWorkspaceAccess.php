<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Loom\Auth;
use Loom\Middleware;
use Loom\Request;
use Loom\Response;

final class RequireWorkspaceAccess implements Middleware
{
    private \Loom\Auth $auth;
    private \App\Services\Access\Admin $admin;

    public function __construct(\Loom\Auth $auth, \App\Services\Access\Admin $admin)
    {
        $this->auth = $auth;
        $this->admin = $admin;
    }

    public function handle(Request $request, callable $next): Response
    {
        $user = $this->auth->guard();
        $this->admin->require($user['id'], 'workspace.use');
        $response = $next($request->withAttribute('user', $user));
        if (!in_array($request->method(), ['GET', 'HEAD'], true) && $response->toArray()['status'] < 400) {
            $this->admin->audit($user['id'], 'workspace.' . strtolower($request->method()), $request->path());
        }
        return $response;
    }
}
