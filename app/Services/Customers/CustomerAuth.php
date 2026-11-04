<?php

declare(strict_types=1);

namespace App\Services\Customers;

use Loom\HttpError;
use App\Models\Customers\Customer;
use App\Models\Customers\CustomerSession;

final class CustomerAuth
{
    private \Loom\Database $database;
    private \Loom\RequestContext $context;
    private \Loom\Auth $origin;

    public function __construct(\Loom\Database $database, \Loom\RequestContext $context, \Loom\Auth $origin)
    {
        $this->database = $database;
        $this->context = $context;
        $this->origin = $origin;
    }

    private function token(): string
    {
        foreach (explode(';', $this->context->current()->header('cookie')) as $cookie) {
            $pair = explode('=', trim($cookie), 2);
            if ($pair[0] === 'loom_customer_session') {
                return $pair[1] ?? '';
            }
        }
        return '';
    }

    private function cookie(string $token, int $age): string
    {
        return 'loom_customer_session=' . $token . '; Path=/; Max-Age=' . $age . '; HttpOnly; SameSite=Lax' . (str_starts_with($this->context->current()->origin(), 'https://') ? '; Secure' : '');
    }

    public function guard(): array
    {
        $user = CustomerSession::identity($this->database, hash('sha256', $this->token()));
        if ($user === null) {
            throw new HttpError(401, '请先登录用户账号');
        }
        $user['id'] = (int)$user['id'];
        if (!in_array($this->context->current()->method(), ['GET', 'HEAD'], true)) {
            $this->origin->checkOrigin();
            if (!hash_equals($user['csrf'], $this->context->current()->header('x-csrf-token'))) {
                throw new HttpError(403, '请求校验失败，请刷新页面');
            }
        }
        return $user;
    }

    public function create(string $name, string $email, string $password): int
    {
        $name = trim($name);
        $email = strtolower(trim($email));
        if ($name === '' || strlen($name) > 80 || !preg_match('/^[^\s@]+@[^\s@]+\.[^\s@]+$/D', $email) || strlen($email) > 254 || strlen($password) < 10 || strlen($password) > 72) {
            throw new HttpError(422, '请填写姓名、有效邮箱和 10–72 字节密码');
        }
        $result = Customer::insertOrIgnore(['name' => $name, 'email' => $email, 'password' => password_hash($password, PASSWORD_BCRYPT, ['cost' => 10]), 'enabled' => 1, 'created' => time()], ['email']);
        if ($result->rowsAffected !== 1) {
            throw new HttpError(409, '此用户邮箱已注册');
        }
        return $result->insertId;
    }

    public function authenticate(string $name, string $email, string $password, bool $register): array
    {
        $this->origin->checkOrigin();
        $email = strtolower(trim($email));
        if (\Loom\Models\AuthLimit::recordAttempt($this->database, 'customer:' . hash('sha256', $email), time()) > 10) {
            throw new HttpError(429, '尝试过于频繁，请稍后再试');
        }
        if ($register) {
            $id = $this->create($name, $email, $password);
        } else {
            $rows = Customer::whereEmail($email)->rows(['id', 'password', 'enabled']);
            $hash = $rows[0]['password'] ?? '$2a$10$N9qo8uLOickgx2ZMRZoMyeIjZAgcfl7p92ldGxad68LJZdL17lhWy';
            if (!password_verify($password, $hash) || $rows === [] || (int)$rows[0]['enabled'] !== 1) {
                throw new HttpError(401, '用户邮箱或密码不正确，或账号已停用');
            }
            $id = (int)$rows[0]['id'];
        }
        $token = bin2hex(random_bytes(32));
        $csrf = bin2hex(random_bytes(32));
        $this->database->transaction(function () use ($token, $csrf, $id): void {
            CustomerSession::whereToken(hash('sha256', $this->token()))->delete();
            CustomerSession::where('expires', '<=', time())->delete();
            CustomerSession::create(['token' => hash('sha256', $token), 'customer_id' => $id, 'csrf' => $csrf, 'expires' => time() + 86400]);
        });
        return ['cookie' => $this->cookie($token, 86400), 'csrf' => $csrf];
    }

    public function logout(): string
    {
        $this->guard();
        CustomerSession::whereToken(hash('sha256', $this->token()))->delete();
        return $this->cookie('', 0);
    }
}
