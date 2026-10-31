<?php
declare(strict_types=1);

namespace App\WebSocket\Handlers;

use App\Services\GreetingService;

final class GreetingHandler
{
    public function greet(\Loom\Request $request, \Net\Websocket\Conn $connection): void
    {
        $name = trim($connection->readText());
        if ($name === '' || strlen($name) > 100) {
            $connection->writeText(json_encode(['error' => 'invalid_name']));
            return;
        }
        $connection->writeText(json_encode((new GreetingService())->greet($name, 1)));
    }
}
