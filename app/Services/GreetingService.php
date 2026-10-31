<?php
declare(strict_types=1);

namespace App\Services;


final class GreetingService
{
    public function greet(string $name, int $times): array
    {
        return ['message' => 'Hello, ' . $name, 'times' => $times];
    }
}
