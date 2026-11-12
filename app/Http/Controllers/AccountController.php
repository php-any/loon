<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Identity\Account;
use Loom\Auth;
use Loom\HttpError;
use Loom\Request;
use Loom\Response;

#[\Net\Annotation\Controller]
final class AccountController
{
    private \Loom\Auth $auth;
    private \App\Services\Identity\Account $account;
    private \App\Services\Access\Admin $admin;

    public function __construct(\Loom\Auth $auth, \App\Services\Identity\Account $account, \App\Services\Access\Admin $admin)
    {
        $this->auth = $auth;
        $this->account = $account;
        $this->admin = $admin;
    }

    #[\Net\Annotation\PostMapping('/api/account/{operation}')]
    public function handle(Request $request): Response
    {
        $this->auth->checkOrigin();
        $input = $request->json();
        $operation = $request->parameter('operation');
        if ($operation === 'request-reset') {
            $input->only(['email']);
            $this->account->requestToken($input->string('email', 3, 254), 'reset');
        } elseif ($operation === 'request-verify') {
            $input->only([]);
            $user = $this->auth->guard();
            $this->admin->assertActive($user['id']);
            $this->account->requestToken($user['email'], 'verify');
        } elseif ($operation === 'reset') {
            $input->only(['token', 'password']);
            $this->account->consume($input->string('token', 64, 64), 'reset', $input->string('password', 10, 72));
        } elseif ($operation === 'verify') {
            $input->only(['token']);
            $this->account->consume($input->string('token', 64, 64), 'verify');
        } else {
            throw new HttpError(404, '操作不存在');
        }
        return Response::json(['ok' => true, 'message' => str_starts_with($operation, 'request-') ? '若账号符合条件，验证码将发送至邮箱，请稍后查收。' : '操作完成，请重新登录或刷新账户状态。'])->withHeader('Cache-Control', 'no-store');
    }
}
