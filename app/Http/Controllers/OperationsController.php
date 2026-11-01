<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Operations\Operations;
use Loom\Auth;
use Loom\Request;
use Loom\Response;

#[\Loom\Attributes\Controller]
final class OperationsController
{
    private \Loom\Auth $auth;
    private \App\Services\Operations\Operations $operations;

    public function __construct(\Loom\Auth $auth, \App\Services\Operations\Operations $operations)
    {
        $this->auth = $auth;
        $this->operations = $operations;
    }

    #[\Loom\Attributes\Route('GET', '/api/admin/operations/{resource}')]
    public function listing(Request $request): Response
    {
        $user = $this->auth->guard();
        $query = [];
        foreach (['page', 'id', 'q', 'status', 'owner'] as $key) {
            $query[$key] = $request->query($key);
        }
        return Response::json($this->operations->listing($request->parameter('resource'), $user['id'], $query))->withHeader('Cache-Control', 'no-store');
    }
    #[\Loom\Attributes\Route('POST', '/api/admin/operations/{resource}')]
    public function mutate(Request $request): Response
    {
        $user = $this->auth->guard();
        return Response::json($this->operations->mutate($request->parameter('resource'), $user['id'], $request->json()))->withHeader('Cache-Control', 'no-store');
    }
}
