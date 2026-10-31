<?php
declare(strict_types=1);

namespace App\Tcp\Handlers;

use App\Services\GreetingService;

final class GreetingHandler
{
    public function handle(array $message): array
    {
        $action = $message['action'] ?? '';
        if ($action === 'ping') {
            return ['ok' => true, 'result' => ['pong' => true]];
        }
        if ($action !== 'greet') {
            return ['ok' => false, 'error' => 'unknown_action'];
        }
        $name = $message['name'] ?? '';
        if (!is_string($name) || trim($name) === '' || strlen($name) > 100) {
            return ['ok' => false, 'error' => 'invalid_name'];
        }
        return ['ok' => true, 'result' => (new GreetingService())->greet(trim($name), 1)];
    }
}
