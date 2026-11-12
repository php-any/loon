<?php

declare(strict_types=1);

namespace App\Http\Middleware;

final class RequireImSocket implements \Loom\Middleware
{
    private \Loom\Auth $origin;
    private \App\Services\Im\ImAuth $auth;
    private \App\Services\Access\Admin $admin;

    public function __construct(\Loom\Auth $origin, \App\Services\Im\ImAuth $auth, \App\Services\Access\Admin $admin)
    {
        $this->origin = $origin;
        $this->auth = $auth;
        $this->admin = $admin;
    }

    public function handle(\Loom\Request $request, callable $next): \Loom\Response
    {
        $this->origin->checkOrigin();
        $user = $this->auth->user();
        $this->admin->assertActive($user['id']);
        return $next($request->withAttribute('im_user_id', $user['id']));
    }
}
