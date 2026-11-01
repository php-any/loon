<?php

declare(strict_types=1);

namespace App\Http\Middleware;

final class RequireNotificationSocket implements \Loom\Middleware
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
        return $next($request->withAttribute('notification_user', $user['id']));
    }
}
