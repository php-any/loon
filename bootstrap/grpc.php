<?php
declare(strict_types=1);

require_once __DIR__ . '/autoload.php';
$routes = require dirname(__DIR__) . '/routes/grpc.php';
return \Loom\Grpc\Dispatcher::handle($routes, dirname(__DIR__) . '/config/grpc.generated.json');
