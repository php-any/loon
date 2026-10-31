<?php
declare(strict_types=1);

use App\Grpc\Services\GreeterService;

return [
    '/loom.examples.Greeter/SayHello' => [GreeterService::class, 'sayHello'],
    '/loom.examples.Greeter/Greet' => [GreeterService::class, 'greet'],
    '/loom.examples.Greeter/BatchGreet' => [GreeterService::class, 'batchGreet'],
];
