<?php

declare(strict_types=1);

namespace App\Http\Middleware;

final class RequireIm implements \Loom\Middleware
{
    private \App\Services\Im\ImAuth $auth;
    private \App\Services\Access\Admin $admin;

    public function __construct(\App\Services\Im\ImAuth $auth, \App\Services\Access\Admin $admin)
    {
        $this->auth = $auth;
        $this->admin = $admin;
    }

    public function handle(\Loom\Request $request, callable $next): \Loom\Response
    {
        $user = $this->auth->guard();
        $this->admin->assertActive($user['id']);
        return $next($request->withAttribute('im_user_id', $user['id']));
    }
}
