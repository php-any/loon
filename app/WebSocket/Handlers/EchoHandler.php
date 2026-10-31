<?php
declare(strict_types=1);

namespace App\WebSocket\Handlers;

final class EchoHandler
{
    public function echoMessage(\Loom\Request $request, \Net\Websocket\Conn $connection): void
    {
        $connection->writeText($connection->readText());
    }
}
