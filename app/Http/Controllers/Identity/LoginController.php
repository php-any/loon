<?php

declare(strict_types=1);

namespace App\Http\Controllers\Identity;

use Loom\Request;
use Loom\Response;

#[\Loom\Attributes\Controller]
final class LoginController
{
    private \Loom\Auth $auth;
    private \App\Services\Access\Admin $admin;

    public function __construct(\Loom\Auth $auth, \App\Services\Access\Admin $admin)
    {
        $this->auth = $auth;
        $this->admin = $admin;
    }

    #[\Loom\Attributes\Route('POST', '/api/studio/login', '', [\Loom\Http\Middleware\ParseJsonInput::class])]
    public function login(Request $request): Response
    {
        $input = $request->attribute('input')->only(['email', 'password']);
        $session = $this->auth->login($input->string('email', 3, 254), $input->string('password', 10, 72));
        try {
            $this->admin->assertActive((int)$session['id']);
        } catch (\Loom\HttpError $error) {
            $this->auth->revoke((int)$session['id']);
            throw $error;
        }
        $this->admin->audit((int)$session['id'], 'account.login', (string)$session['id']);
        return Response::json(['csrf' => $session['csrf']])->withHeader('Set-Cookie', $session['cookie']);
    }
}
