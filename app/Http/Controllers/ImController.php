<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Im\ImMessaging;
use Loom\Request;
use Loom\Response;

#[\Net\Annotation\Controller]
#[\Net\Annotation\Middleware(\App\Http\Middleware\RequireIm::class)]
final class ImController
{
    private ImMessaging $im;

    public function __construct(ImMessaging $im)
    {
        $this->im = $im;
    }

  #[\Net\Annotation\GetMapping('/api/im/contacts')]
    public function contacts(Request $request): Response
    {
        $userId = (int)$request->attribute('im_user_id');
        $query = substr($request->query('q')[0] ?? '', 0, 100);
        return Response::json($this->im->contacts($userId, $query))->withHeader('Cache-Control', 'no-store');
    }

    #[\Net\Annotation\GetMapping('/api/im/conversations')]
    public function conversations(Request $request): Response
    {
        $userId = (int)$request->attribute('im_user_id');
        return Response::json($this->im->conversations($userId))->withHeader('Cache-Control', 'no-store');
    }

    #[\Net\Annotation\GetMapping('/api/im/conversations/{id}')]
    public function show(Request $request): Response
    {
        $userId = (int)$request->attribute('im_user_id');
        $id = (int)$request->parameter('id');
        return Response::json($this->im->conversation($id, $userId))->withHeader('Cache-Control', 'no-store');
    }

    #[\Net\Annotation\GetMapping('/api/im/conversations/{id}/messages')]
    public function messages(Request $request): Response
    {
        $userId = (int)$request->attribute('im_user_id');
        $page = max(1, min(100000, (int)($request->query('page')[0] ?? 1)));
        $id = (int)$request->parameter('id');
        return Response::json($this->im->messages($id, $userId, $page))->withHeader('Cache-Control', 'no-store');
    }

    #[\Net\Annotation\PostMapping('/api/im/conversations')]
    public function create(Request $request): Response
    {
        $userId = (int)$request->attribute('im_user_id');
        $payload = $request->json();
        $type = (string)($payload['type'] ?? 'direct');
        $title = isset($payload['title']) ? (string)$payload['title'] : null;
        $members = array_map('intval', (array)($payload['member_ids'] ?? []));
        $conversation = $this->im->createConversation($userId, $type, $title, $members);
        return Response::json($conversation, 201)->withHeader('Cache-Control', 'no-store');
    }

    #[\Loom\Attributes\HttpRoute('PATCH', '/api/im/conversations/{id}', '', [\App\Http\Middleware\RequireIm::class, \Loom\Http\Middleware\ParseJsonInput::class])]
    public function update(Request $request): Response
    {
        $userId = (int)$request->attribute('im_user_id');
        $id = (int)$request->parameter('id');
        $title = (string)($request->attribute('input')->string('title', 1, 255));
        return Response::json($this->im->renameGroup($id, $userId, $title))->withHeader('Cache-Control', 'no-store');
    }

    #[\Net\Annotation\PostMapping('/api/im/conversations/{id}/members')]
    public function addMembers(Request $request): Response
    {
        $userId = (int)$request->attribute('im_user_id');
        $id = (int)$request->parameter('id');
        $memberIds = array_map('intval', (array)($request->json()['member_ids'] ?? []));
        return Response::json($this->im->addMembers($id, $userId, $memberIds))->withHeader('Cache-Control', 'no-store');
    }

    #[\Net\Annotation\DeleteMapping('/api/im/conversations/{id}/members/{userId}')]
    public function removeMember(Request $request): Response
    {
        $actorId = (int)$request->attribute('im_user_id');
        $id = (int)$request->parameter('id');
        $targetId = (int)$request->parameter('userId');
        return Response::json($this->im->removeMember($id, $actorId, $targetId))->withHeader('Cache-Control', 'no-store');
    }

    #[\Net\Annotation\DeleteMapping('/api/im/conversations/{id}')]
    public function dissolve(Request $request): Response
    {
        $userId = (int)$request->attribute('im_user_id');
        $id = (int)$request->parameter('id');
        return Response::json($this->im->dissolve($id, $userId))->withHeader('Cache-Control', 'no-store');
    }

    #[\Net\Annotation\PostMapping('/api/im/conversations/{id}/messages')]
    public function sendMessage(Request $request): Response
    {
        $userId = (int)$request->attribute('im_user_id');
        $id = (int)$request->parameter('id');
        $body = (string)($request->json()['body'] ?? '');
        return Response::json($this->im->sendMessage($id, $userId, $body), 201)->withHeader('Cache-Control', 'no-store');
    }

    #[\Net\Annotation\PostMapping('/api/im/conversations/{id}/read')]
    public function read(Request $request): Response
    {
        $userId = (int)$request->attribute('im_user_id');
        $id = (int)$request->parameter('id');
        $messageId = (int)($request->json()['message_id'] ?? 0);
        $this->im->markRead($id, $userId, $messageId);
        return Response::json(['ok' => true])->withHeader('Cache-Control', 'no-store');
    }
}
