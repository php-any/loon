<?php

declare(strict_types=1);

namespace App\Services\Identity;

use Loom\Database;
use Loom\HttpError;
use Loom\Models\User;
use Loom\Models\AuthLimit;
use App\Models\Identity\Account as AccountRecord;
use App\Models\Identity\AccountToken;
use App\Models\Notifications\Mail;

final class Account
{
    private \Loom\Database $database;
    private \App\Services\Access\Admin $admin;
    private \Loom\Auth $auth;

    public function __construct(\Loom\Database $database, \App\Services\Access\Admin $admin, \Loom\Auth $auth)
    {
        $this->database = $database;
        $this->admin = $admin;
        $this->auth = $auth;
    }

    public function requestToken(string $email, string $purpose): void
    {
        $email = strtolower(trim($email));
        // 响应绝不泄露邮箱是否存在、是否已验证或是否被禁用。
        $this->database->transaction(function () use ($email, $purpose): void {
            $key = 'account:' . hash('sha256', $purpose . ':' . $email);
            if (!AuthLimit::acquireCooldown($this->database, $key, time(), 60)) {
                return;
            }
            $account = AccountRecord::statusForEmail($this->database, $email);
            if ($account === null || (int)($account['enabled'] ?? 1) !== 1 || ($purpose === 'verify' && $account['verified_at'] !== null)) {
                return;
            }
            $id = $account['id'];
            $token = bin2hex(random_bytes(32));
            AccountToken::whereUserId($id)->wherePurpose($purpose)->delete();
            AccountToken::where('expires', '<=', time())->delete();
            AccountToken::create(['token' => hash('sha256', $token), 'user_id' => $id, 'purpose' => $purpose, 'expires' => time() + 1800]);
            $label = $purpose === 'reset' ? '重置密码' : '验证邮箱';
            Mail::whereRecipient($email)->whereSubject('Loom · ' . $label)->whereIn('state', ['pending', 'failed'])->update(['state' => 'expired', 'body' => '']);
            // 邮件不包含请求中的 Host/Origin，防止伪造主机名窃取令牌。
            $body = '你请求了 Loom ' . $label . '。请在原站点 /admin 的账户安全页面粘贴以下验证码。验证码 30 分钟内有效，仅可使用一次。' . "\n\n" . $token . "\n\n如果不是你本人操作，请忽略此邮件。";
            Mail::create(['recipient' => $email, 'subject' => 'Loom · ' . $label, 'body' => $body]);
            $this->admin->audit($id, 'account.' . $purpose . '.requested', (string)$id);
        });
    }

    public function consume(string $token, string $purpose, string $password = ''): void
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $token)) {
            throw new HttpError(422, '验证码无效或已过期');
        }
        if ($purpose === 'reset' && (strlen($password) < 10 || strlen($password) > 72)) {
            throw new HttpError(422, '密码必须为 10–72 字节');
        }
        $hash = $purpose === 'reset' ? password_hash($password, PASSWORD_BCRYPT, ['cost' => 10]) : '';
        $this->database->transaction(function () use ($token, $purpose, $hash): void {
            $digest = hash('sha256', $token);
            $id = AccountToken::lockOwner($this->database, $digest, $purpose, time());
            if ($id === null) {
                throw new HttpError(422, '验证码无效或已过期');
            }
            $this->admin->assertActive($id);
            $this->admin->enroll($id);
            AccountToken::whereUserId($id)->wherePurpose($purpose)->delete();
            $email = User::findOrFail($id)->email;
            Mail::whereRecipient($email)->whereSubject('Loom · ' . ($purpose === 'reset' ? '重置密码' : '验证邮箱'))->whereIn('state', ['pending', 'failed'])->update(['state' => 'expired', 'body' => '']);
            if ($purpose === 'reset') {
                User::whereId($id)->update(['password' => $hash]);
                $this->auth->revoke($id);
            } else {
                AccountRecord::whereUserId($id)->update(['verified_at' => time()]);
            }
            $this->admin->audit($id, 'account.' . $purpose . '.completed', (string)$id);
        });
    }

    public function deliver(): array
    {
        $mail = $this->database->transaction(function (): array {
            // 清理过期令牌，以及验证码已不可用的排队消息。
            AccountToken::where('expires', '<=', time())->delete();
            Mail::where('created', '<=', time() - 1800)->whereIn('state', ['pending', 'sending', 'failed'])->update(['state' => 'expired', 'body' => '']);
            return Mail::claim($this->database, time());
        });
        if ($mail === []) {
            return ['state' => 'empty'];
        }
        try {
            $result = json_decode(\Loom\Native\sendMail($mail['recipient'], $mail['subject'], $mail['body']), true, 32, JSON_THROW_ON_ERROR);
        } catch (\Throwable $error) {
            $result = ['ok' => false];
        }
        $state = $result['ok'] ? 'sent' : ((int)$mail['attempts'] >= 4 ? 'failed' : 'pending');
        $attributes = ['state' => $state, 'available' => time() + 60, 'lease_until' => null];
        if ($result['ok']) {
            $attributes['body'] = '';
        }
        Mail::whereId($mail['id'])->update($attributes);
        return ['id' => (int)$mail['id'], 'state' => $state];
    }
}
