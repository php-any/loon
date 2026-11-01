<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Identity\Account;
use App\Models\Customers\Purchase;
use App\Services\Access\Admin;
use Loom\HttpError;
use Loom\Request;
use Loom\Response;

#[\Loom\Attributes\Controller]
final class CheckoutController
{
    private \App\Services\Customers\CustomerAuth $auth;
    private \Loom\Database $database;
    private \App\Services\Access\Admin $admin;
    private \App\Services\Notifications\Notifications $notifications;

    public function __construct(\App\Services\Customers\CustomerAuth $auth, \Loom\Database $database, \App\Services\Access\Admin $admin, \App\Services\Notifications\Notifications $notifications)
    {
        $this->auth = $auth;
        $this->database = $database;
        $this->admin = $admin;
        $this->notifications = $notifications;
    }

    #[\Loom\Attributes\Route('POST', '/api/checkout/simulate')]
    public function pay(Request $request): Response
    {
        $user = $this->auth->guard();
        $input = $request->json()->only(['request_key']);
        $key = $input->string('request_key', 16, 80);
        if (!preg_match('/^[a-zA-Z0-9-]+$/D', $key)) {
            throw new HttpError(422, '请求标识无效');
        }
        $key = 'customer-' . $user['id'] . '-' . $key;
        $result = $this->database->transaction(function () use ($user, $key): array {
            $created = Purchase::using($this->database)->insertOrIgnore(['number' => 'SIM' . date('YmdHis') . strtoupper(bin2hex(random_bytes(6))), 'customer_id' => $user['id'], 'total' => 9900, 'status' => 'completed', 'request_key' => $key, 'created' => time()], ['request_key']);
            $order = Purchase::using($this->database)->whereRequestKey($key)->firstRow(['id', 'number', 'total', 'status']);
            if ($created->rowsAffected === 1) {
                $body = '用户 ' . $user['name'] . '（' . $user['email'] . '）的订单 ' . $order['number'] . ' 模拟支付成功，金额 ¥99.00（未实际扣款）。';
                foreach (Account::enabledUserIds($this->database) as $recipient) {
                    if (Admin::allows($this->admin->grants($recipient), 'orders.view')) {
                        $this->notifications->emit('customer.payment:' . $order['id'], $recipient, '用户模拟支付成功', $body);
                    }
                }
                // 客户 ID 绝不能写入管理员操作者外键。
                $this->admin->audit(null, 'customer.payment.simulated', (string)$order['id'], ['customer_id' => $user['id'], 'total' => 9900]);
            }
            return ['ok' => true, 'order' => $order, 'simulated' => true];
        });
        return Response::json($result)->withHeader('Cache-Control', 'no-store');
    }
}
