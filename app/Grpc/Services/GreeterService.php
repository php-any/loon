<?php
declare(strict_types=1);

namespace App\Grpc\Services;

use App\Services\GreetingService;

final class GreeterService
{
    public function sayHello(string $name): string
    {
        $name = trim($name);
        if ($name === '' || strlen($name) > 100) {
            return $this->invalid('name must contain 1 to 100 bytes');
        }
        $result = (new GreetingService())->greet($name, 1);
        return $result['message'];
    }

    public function greet(array $request): array
    {
        $name = trim($request['name'] ?? '');
        $times = $request['times'] ?? 1;
        if ($name === '' || strlen($name) > 100 || $times < 1 || $times > 10) {
            return $this->invalid('name must contain 1 to 100 bytes; times must be 1 to 10');
        }
        $greetings = [];
        for ($i = 0; $i < $times; $i++) {
            $result = (new GreetingService())->greet($name, 1);
            $greetings[] = ['name' => $name, 'message' => $result['message']];
        }
        return ['greetings' => $greetings];
    }

    public function batchGreet(array $request): array
    {
        $names = $request['names'] ?? [];
        if (count($names) < 1 || count($names) > 1000) {
            return $this->invalid('names must contain 1 to 1000 entries');
        }
        $greetings = [];
        foreach ($names as $name) {
            $name = trim($name);
            if ($name === '' || strlen($name) > 100) {
                return $this->invalid('each name must contain 1 to 100 bytes');
            }
            $result = (new GreetingService())->greet($name, 1);
            $greetings[] = ['name' => $name, 'message' => $result['message']];
        }
        return ['greetings' => $greetings, 'count' => count($greetings)];
    }

    private function invalid(string $message): array
    {
        throw new \Loom\Grpc\RpcException('invalid_argument', $message);
    }
}
