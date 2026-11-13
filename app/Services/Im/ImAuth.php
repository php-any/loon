<?php

declare(strict_types=1);

namespace App\Services\Im;

use App\Models\Identity\Account;
use Loom\HttpError;
use Loom\Models\AuthLimit;
use Loom\Models\User;

final class ImAuth
{
    private const COOKIE = 'loom_im_session';

    private \Loom\Database $database;
    private \Loom\RequestContext $context;
    private \Loom\Auth $origin;

    public function __construct(\Loom\Database $database, \Loom\RequestContext $context, \Loom\Auth $origin)
    {
        $this->database = $database;
        $this->context = $context;
        $this->origin = $origin;
    }

    public function assertEnabledIdentity(int $id): void
    {
        $account = Account::whereUserId($id)->rows(['enabled']);
        if ($account === [] || (int)$account[0]['enabled'] !== 1) {
            throw new HttpError(403, '账号未开通或已停用');
        }
    }

    private function sessionToken(): string
    {
        foreach (explode(';', $this->context->current()->header('cookie')) as $cookie) {
            $pair = explode('=', trim($cookie), 2);
            if (($pair[0] ?? '') === self::COOKIE) {
                return $pair[1] ?? '';
            }
        }
        return '';
    }

    private function cookie(string $token, int $age): string
    {
        return self::COOKIE . '=' . $token . '; Path=/; Max-Age=' . $age . '; HttpOnly; SameSite=Lax' . (str_starts_with($this->context->current()->origin(), 'https://') ? '; Secure' : '');
    }

    private function deleteSession(): void
    {
        $token = $this->sessionToken();
        if ($token !== '') {
            \Loom\Redis::command(['DEL', \Loom\Redis::key('im_session:' . hash('sha256', $token))]);
        }
    }

    public function user(): array
    {
        $token = $this->sessionToken();
        if ($token === '') {
            throw new HttpError(401, '请先登录聊天');
        }
        $raw = \Loom\Redis::command(['GET', \Loom\Redis::key('im_session:' . hash('sha256', $token))]);
        if ($raw === null) {
            throw new HttpError(401, '聊天登录已过期，请重新登录');
        }
        $session = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        $rows = User::whereId($session['id'])->rows(['id', 'name', 'email', 'auth_version']);
        if ($rows === [] || (int)$rows[0]['auth_version'] !== (int)$session['version']) {
            throw new HttpError(401, '聊天登录已过期，请重新登录');
        }
        $user = $rows[0];
        unset($user['auth_version']);
        $user['id'] = (int)$user['id'];
        $user['csrf'] = $session['csrf'];
        return $user;
    }

    public function guard(): array
    {
        $user = $this->user();
        $request = $this->context->current();
        if (!in_array($request->method(), ['GET', 'HEAD'], true)) {
            if (!hash_equals($user['csrf'], $request->header('x-csrf-token'))) {
                throw new HttpError(403, '请求校验失败，请刷新页面');
            }
            $this->origin->checkOrigin();
        }
        return $user;
    }

    public function login(string $email, string $password): array
    {
        $this->origin->checkOrigin();
        $email = strtolower(trim($email));
        if (strlen($email) > 254 || !str_contains($email, '@') || strlen($password) < 10 || strlen($password) > 72) {
            throw new HttpError(422, '请输入有效邮箱和 10–72 字节密码');
        }
        if (AuthLimit::recordAttempt($this->database, 'im:' . hash('sha256', $email), time()) > 10) {
            throw new HttpError(429, '尝试过于频繁，请稍后再试');
        }
        $rows = User::whereEmail($email)->rows(['id', 'password', 'auth_version']);
        $hash = $rows[0]['password'] ?? '$2a$10$N9qo8uLOickgx2ZMRZoMyeIjZAgcfl7p92ldGxad68LJZdL17lhWy';
        if (!password_verify($password, $hash) || $rows === []) {
            throw new HttpError(401, '邮箱或密码不正确');
        }
        $id = (int)$rows[0]['id'];
        $this->assertEnabledIdentity($id);
        $token = bin2hex(random_bytes(32));
        $csrf = bin2hex(random_bytes(32));
        $version = (int)$rows[0]['auth_version'];
        $this->deleteSession();
        \Loom\Redis::command(['SET', \Loom\Redis::key('im_session:' . hash('sha256', $token)), json_encode(['id' => $id, 'csrf' => $csrf, 'version' => $version], JSON_THROW_ON_ERROR), 'EX', '86400']);
        return ['cookie' => $this->cookie($token, 86400), 'csrf' => $csrf, 'id' => $id];
    }

    public function logout(): array
    {
        $this->guard();
        $this->deleteSession();
        return ['cookie' => $this->cookie('', 0)];
    }
}
