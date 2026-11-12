<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Im\ImMessaging;
use Loom\Request;
use Loom\Response;

#[\Net\Annotation\Controller]
#[\Net\Annotation\Route('/api/admin')]
final class ImController
{
    private \Loom\Auth $auth;
    private \App\Services\Access\Admin $admin;
    private ImMessaging $im;

    public function __construct(\Loom\Auth $auth, \App\Services\Access\Admin $admin, ImMessaging $im)
    {
        $this->auth = $auth;
        $this->admin = $admin;
        $this->im = $im;
    }

    #[\Net\Annotation\GetMapping('/im/conversations')]
    public function conversations(Request $request): Response
    {
        $user = $this->auth->guard();
        $this->admin->require($user['id'], 'workspace.use');
        return Response::json($this->im->conversations($user['id']))->withHeader('Cache-Control', 'no-store');
    }

    #[\Net\Annotation\GetMapping('/im/conversations/{id}/messages')]
    public function messages(Request $request): Response
    {
        $user = $this->auth->guard();
        $this->admin->require($user['id'], 'workspace.use');
        $page = max(1, min(100000, (int)($request->query('page')[0] ?? 1)));
        $id = (int)$request->parameter('id');
        return Response::json($this->im->messages($id, $user['id'], $page))->withHeader('Cache-Control', 'no-store');
    }

    #[\Net\Annotation\PostMapping('/im/conversations')]
    public function create(Request $request): Response
    {
        $user = $this->auth->guard();
        $this->admin->require($user['id'], 'workspace.use');
        $payload = $request->json();
        $type = (string)($payload['type'] ?? 'direct');
        $title = isset($payload['title']) ? (string)$payload['title'] : null;
        $members = array_map('intval', (array)($payload['member_ids'] ?? []));
        $conversation = $this->im->createConversation($user['id'], $type, $title, $members);
        return Response::json($conversation, 201)->withHeader('Cache-Control', 'no-store');
    }
}
