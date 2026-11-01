<?php

declare(strict_types=1);

namespace App\Grpc;

use Loom\Grpc\RouteProvider;
use App\Grpc\Services\ReleasePolicyService;

final class ApplicationRoutes implements RouteProvider
{
    public function routes(): array
    {
        return [
            '/loom.examples.ReleasePolicy/Evaluate' => [ReleasePolicyService::class, 'evaluate'],
        ];
    }
}
