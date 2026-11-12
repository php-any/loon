<?php

declare(strict_types=1);

namespace App\Http\Middleware;

final class RequireImSocket implements \Loom\Middleware
{
    private \Loom\Auth $auth;
    private \App\Services\Access\Admin $admin;

    public function __construct(\Loom\Auth $auth, \App\Services\Access\Admin $admin)
    {
        $this->auth = $auth;
        $this->admin = $admin;
    }

    public function handle(\Loom\Request $request, callable $next): \Loom\Response
    {
        $this->auth->checkOrigin();
        $user = $this->auth->guard();
        $this->admin->assertActive($user['id']);
        $this->admin->require($user['id'], 'workspace.use');
        return $next($request->withAttribute('im_user_id', $user['id']));
    }
}
