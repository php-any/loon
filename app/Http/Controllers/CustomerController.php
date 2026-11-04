<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Loom\Request;
use Loom\Response;
use Loom\HttpError;
use App\Models\Customers\Customer;
use App\Models\Customers\CustomerSession;
use App\Models\Customers\Purchase;
use App\Services\Customers\CheckoutOffer;

#[\Loom\Attributes\Controller]
final class CustomerController
{
    #[\Loom\Attributes\Route('GET', '/account')]
    public function page(Request $request): Response
    {
        return \App\Presentation\SitePages::page('customer', '用户中心', [
            'planName' => $this->offer->name(),
            'planPrice' => $this->offer->price(),
        ]);
    }

    private \App\Services\Customers\CustomerAuth $customers;
    private \Loom\Auth $auth;
    private \Loom\Database $database;
    private \App\Services\Access\Admin $admin;
    private CheckoutOffer $offer;

    public function __construct(\App\Services\Customers\CustomerAuth $customers, \Loom\Auth $auth, \Loom\Database $database, \App\Services\Access\Admin $admin, CheckoutOffer $offer)
    {
        $this->customers = $customers;
        $this->auth = $auth;
        $this->database = $database;
        $this->admin = $admin;
        $this->offer = $offer;
    }

    #[\Loom\Attributes\Route('GET', '/api/customer/session')]
    public function session(Request $request): Response
    {
        return Response::json(['user' => $this->customers->guard()])->withHeader('Cache-Control', 'no-store');
    }

    #[\Loom\Attributes\Route('POST', '/api/customer/{operation}')]
    public function account(Request $request): Response
    {
        $operation = $request->parameter('operation');
        if ($operation === 'logout') {
            return Response::json(['ok' => true])->withHeader('Set-Cookie', $this->customers->logout())->withHeader('Cache-Control', 'no-store');
        }
        if (!in_array($operation, ['register', 'login'], true)) {
            throw new HttpError(404, '操作不存在');
        }
        $register = $operation === 'register';
        if ($register && $this->admin->settings()['registration_enabled'] !== 'true') {
            throw new HttpError(403, '当前已关闭用户注册');
        }
        $input = $request->json()->only($register ? ['name', 'email', 'password'] : ['email', 'password']);
        $session = $this->customers->authenticate($register ? $input->string('name', 1, 80) : '', $input->string('email', 3, 254), $input->string('password', 10, 72), $register);
        return Response::json(['csrf' => $session['csrf']], $register ? 201 : 200)->withHeader('Set-Cookie', $session['cookie'])->withHeader('Cache-Control', 'no-store');
    }

    #[\Loom\Attributes\Route('GET', '/api/customer/orders')]
    public function orders(Request $request): Response
    {
        $user = $this->customers->guard();
        $items = Purchase::whereCustomerId($user['id'])->orderBy('id', 'DESC')->limit(25)->rows(['id', 'number', 'total', 'status', 'created']);
        return Response::json(['items' => $items])->withHeader('Cache-Control', 'no-store');
    }

    #[\Loom\Attributes\Route('GET', '/api/admin/customers')]
    public function listing(Request $request): Response
    {
        $actor = $this->auth->guard();
        $this->admin->require($actor['id'], 'customers.manage');
        $q = substr($request->query('q')[0] ?? '', 0, 100);
        $page = max(1, min(100000, (int)($request->query('page')[0] ?? 1)));
        return Response::json(Customer::listing($this->database, $page, $q))->withHeader('Cache-Control', 'no-store');
    }

    #[\Loom\Attributes\Route('POST', '/api/admin/customers')]
    public function save(Request $request): Response
    {
        $actor = $this->auth->guard();
        $this->admin->require($actor['id'], 'customers.manage');
        $input = $request->json();
        $id = $input->integer('id', 0);
        $input->only($id === 0 ? ['id', 'name', 'email', 'password', 'enabled'] : ['id', 'name', 'enabled']);
        $name = trim($input->string('name', 1, 80));
        $enabled = $input->boolean('enabled');
        if ($name === '') {
            throw new HttpError(422, '姓名不能为空');
        }
        $result = $this->database->transaction(function () use ($actor, $input, $id, $name, $enabled): array {
            if ($id === 0) {
                $id = $this->customers->create($name, $input->string('email', 3, 254), $input->string('password', 10, 72));
            } elseif (!Customer::whereId($id)->exists()) {
                throw new HttpError(404, '用户不存在');
            }
            Customer::whereId($id)->update(['name' => $name, 'enabled' => $enabled ? 1 : 0]);
            if (!$enabled) {
                CustomerSession::whereCustomerId($id)->delete();
            }
            $this->admin->audit($actor['id'], 'customers.save', (string)$id, ['name' => $name, 'enabled' => $enabled]);
            return ['ok' => true, 'id' => $id];
        });
        return Response::json($result);
    }

    #[\Loom\Attributes\Route('GET', '/api/admin/purchases')]
    public function purchases(Request $request): Response
    {
        $actor = $this->auth->guard();
        $this->admin->require($actor['id'], 'orders.view');
        $q = substr($request->query('q')[0] ?? '', 0, 100);
        $page = max(1, min(100000, (int)($request->query('page')[0] ?? 1)));
        return Response::json(Purchase::listing($this->database, $page, $q))->withHeader('Cache-Control', 'no-store');
    }
}
