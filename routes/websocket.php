<?php
declare(strict_types=1);

use App\WebSocket\Handlers\EchoHandler;
use App\WebSocket\Handlers\GreetingHandler;

$app->websocket('/ws/echo', [EchoHandler::class, 'echoMessage'], 'websocket.echo');
$app->websocket('/ws/greet', [GreetingHandler::class, 'greet'], 'websocket.greet');
