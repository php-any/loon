<?php

declare(strict_types=1);

namespace WebSocketFrameworkFixtures;

#[\Container\Annotation\Scoped]
final class EchoSocketHandler
{
    #[\Loom\Attributes\WebSocket('/fixture/socket')]
    public function connect(): void
    {
    }

    public function onOpen(\Loom\Request $request, \Net\Websocket\Conn $connection): void
    {
        $connection->writeText('{"type":"ready"}');
    }

    #[\Loom\Attributes\WebSocketMessage('echo')]
    public function echo(array $message, \Net\Websocket\Conn $connection): void
    {
        $connection->writeText(json_encode(['type' => 'echo', 'body' => $message['body'] ?? ''], JSON_THROW_ON_ERROR));
    }
}

#[\Net\Annotation\Controller]
final class ConflictSocketController
{
    #[\Net\Annotation\GetMapping('/fixture/conflict')]
    #[\Loom\Attributes\WebSocket('/fixture/conflict')]
    public function both(): void
    {
    }
}
