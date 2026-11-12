<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Im\ImAuth;
use Loom\Request;
use Loom\Response;

#[\Net\Annotation\Controller]
#[\Net\Annotation\Middleware(\Loom\Http\Middleware\ParseJsonInput::class)]
final class ImSessionController
{
    private ImAuth $auth;

    public function __construct(ImAuth $auth)
    {
        $this->auth = $auth;
    }

    #[\Net\Annotation\PostMapping('/api/im/login')]
    public function login(Request $request): Response
    {
        $input = $request->attribute('input');
        $session = $this->auth->login($input->string('email', 3, 254), $input->string('password', 10, 72));
        return Response::json(['csrf' => $session['csrf'], 'user' => ['id' => $session['id']]])->withHeader('Set-Cookie', $session['cookie']);
    }

    #[\Net\Annotation\PostMapping('/api/im/logout')]
    public function logout(Request $request): Response
    {
        $result = $this->auth->logout();
        return Response::json(['ok' => true])->withHeader('Set-Cookie', $result['cookie']);
    }

    #[\Net\Annotation\GetMapping('/api/im/session')]
    public function session(Request $request): Response
    {
        $user = $this->auth->guard();
        return Response::json(['user' => ['id' => $user['id'], 'name' => $user['name'], 'email' => $user['email'], 'csrf' => $user['csrf']]])->withHeader('Cache-Control', 'no-store');
    }
}
