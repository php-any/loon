<?php

declare(strict_types=1);

namespace App\Http\Middleware;

final class RequireImSocket implements \Loom\Middleware
{
    private \Loom\Auth $origin;
    private \App\Services\Im\ImAuth $auth;

    public function __construct(\Loom\Auth $origin, \App\Services\Im\ImAuth $auth)
    {
        $this->origin = $origin;
        $this->auth = $auth;
    }

    public function handle(\Loom\Request $request, callable $next): \Loom\Response
    {
        $this->origin->checkOrigin();
        $user = $this->auth->user();
        $this->auth->assertEnabledIdentity($user['id']);
        return $next($request->withAttribute('im_user_id', $user['id']));
    }
}
