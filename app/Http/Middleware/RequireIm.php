<?php

declare(strict_types=1);

namespace App\Http\Middleware;

final class RequireIm implements \Loom\Middleware
{
    private \App\Services\Im\ImAuth $auth;

    public function __construct(\App\Services\Im\ImAuth $auth)
    {
        $this->auth = $auth;
    }

    public function handle(\Loom\Request $request, callable $next): \Loom\Response
    {
        $user = $this->auth->guard();
        $this->auth->assertEnabledIdentity($user['id']);
        return $next($request->withAttribute('im_user_id', $user['id']));
    }
}
