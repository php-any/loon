<?php

declare(strict_types=1);

namespace App\WebSocket\Handlers;

use App\Services\Im\ImMessaging;

#[\Container\Annotation\Scoped]
final class ImSocketHandler
{
    private ImMessaging $im;
    private \Loom\WebSocket\Hub $hub;
    private int $userId = 0;
    /** @var array<string, string> */
    private array $streamCursors = [];

    public function __construct(ImMessaging $im, \Loom\WebSocket\Hub $hub)
    {
        $this->im = $im;
        $this->hub = $hub;
    }

    #[\Loom\Attributes\WebSocket('/api/im/socket', middleware: [\App\Http\Middleware\RequireImSocket::class])]
    public function connect(): void
    {
    }

    public function onOpen(\Loom\Request $request, \Net\Websocket\Conn $connection): void
    {
        $this->userId = (int)$request->attribute('im_user_id');
        $userChannel = ImMessaging::userChannel($this->userId);
        \Loom\Redis::using(function ($client) use ($userChannel): void {
            $this->streamCursors[$userChannel] = $this->hub->tail($client, $userChannel);
        });
        $connection->writeText(json_encode(['type' => 'heartbeat', 'user_id' => $this->userId], JSON_THROW_ON_ERROR));
    }

    public function poll(\Net\Websocket\Conn $connection): void
    {
        if ($this->streamCursors === []) {
            return;
        }
        \Loom\Redis::using(function ($client) use ($connection): void {
            $events = $this->hub->read($client, $this->streamCursors, 0, 50);
            foreach ($events as $event) {
                $this->streamCursors[$event['channel']] = $event['cursor'];
                if ($event['payload'] !== '') {
                    $connection->writeText($event['payload']);
                }
            }
        });
    }

    #[\Loom\Attributes\WebSocketMessage('join')]
    public function join(array $message, \Net\Websocket\Conn $connection): void
    {
        $conversationId = (int)($message['conversation_id'] ?? 0);
        if ($conversationId < 1) {
            $connection->writeText(json_encode(['type' => 'error', 'message' => '无效的会话'], JSON_THROW_ON_ERROR));
            return;
        }
        try {
            $this->im->assertMember($conversationId, $this->userId);
        } catch (\Loom\HttpError $error) {
            $connection->writeText(json_encode(['type' => 'error', 'message' => $error->getMessage()], JSON_THROW_ON_ERROR));
            return;
        }
        $channel = ImMessaging::conversationChannel($conversationId);
        \Loom\Redis::using(function ($client) use ($channel, $conversationId, $connection): void {
            $this->streamCursors[$channel] = $this->hub->tail($client, $channel);
        });
        $connection->writeText(json_encode(['type' => 'joined', 'conversation_id' => $conversationId], JSON_THROW_ON_ERROR));
    }

    #[\Loom\Attributes\WebSocketMessage('send')]
    public function send(array $message, \Net\Websocket\Conn $connection): void
    {
        $conversationId = (int)($message['conversation_id'] ?? 0);
        try {
            $this->im->sendMessage($conversationId, $this->userId, (string)($message['body'] ?? ''));
        } catch (\Loom\HttpError $error) {
            $connection->writeText(json_encode(['type' => 'error', 'message' => $error->getMessage()], JSON_THROW_ON_ERROR));
        }
    }

    #[\Loom\Attributes\WebSocketMessage('read')]
    public function read(array $message, \Net\Websocket\Conn $connection): void
    {
        $conversationId = (int)($message['conversation_id'] ?? 0);
        $messageId = (int)($message['message_id'] ?? 0);
        try {
            $this->im->markRead($conversationId, $this->userId, $messageId);
        } catch (\Loom\HttpError $error) {
            $connection->writeText(json_encode(['type' => 'error', 'message' => $error->getMessage()], JSON_THROW_ON_ERROR));
            return;
        }
        $connection->writeText(json_encode(['type' => 'read', 'conversation_id' => $conversationId, 'message_id' => $messageId], JSON_THROW_ON_ERROR));
    }
}
