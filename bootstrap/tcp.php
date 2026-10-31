<?php
declare(strict_types=1);

require_once __DIR__ . '/autoload.php';
$message = json_decode(\Loom\message_envelope(), true);
return (new \App\Tcp\Handlers\GreetingHandler())->handle($message);
