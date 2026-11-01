<?php

declare(strict_types=1);

namespace App\Http\Controllers\Identity;

use Loom\Action;
use Loom\Request;
use Loom\Response;
use Loom\Auth;

#[\Loom\Attributes\Controller]
#[\Loom\Attributes\Route('POST', '/api/studio/logout')]
final class LogoutController implements Action
{
    private \Loom\Auth $auth;
    private \App\Services\Access\Admin $admin;

    public function __construct(\Loom\Auth $auth, \App\Services\Access\Admin $admin)
    {
        $this->auth = $auth;
        $this->admin = $admin;
    }

    public function __invoke(Request $request): Response
    {
        $user = $this->auth->guard();
        $session = $this->auth->logout();
        $this->admin->audit($user['id'], 'account.logout', (string)$user['id']);
        return Response::json(['ok' => true])->withHeader('Set-Cookie', $session['cookie']);
    }
}
