<?php

declare(strict_types=1);

namespace App\Http\Controllers\System;

use Loom\Request;
use Loom\Response;
use Loom\RuntimeStatus;

#[\Loom\Attributes\Controller]
final class RuntimeController
{
    private \Loom\RuntimeStatus $runtimeStatus;

    public function __construct(\Loom\RuntimeStatus $runtimeStatus)
    {
        $this->runtimeStatus = $runtimeStatus;
    }

    #[\Loom\Attributes\Route('GET', '/api/studio/runtime')]
    public function status(Request $request): Response
    {
        return Response::json(['services' => $this->runtimeStatus->snapshot(), 'now' => time()])->withHeader('Cache-Control', 'no-store');
    }
}
