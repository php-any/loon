<?php

declare(strict_types=1);

namespace App\Http\Controllers\System;

use Loom\Action;
use Loom\Request;
use Loom\Response;
use Loom\RuntimeStatus;

#[\Loom\Attributes\Controller]
#[\Loom\Attributes\Route('GET', '/api/studio/runtime')]
final class RuntimeController implements Action
{
    private \Loom\RuntimeStatus $runtimeStatus;

    public function __construct(\Loom\RuntimeStatus $runtimeStatus)
    {
        $this->runtimeStatus = $runtimeStatus;
    }

    public function __invoke(Request $request): Response
    {
        return Response::json(['services' => $this->runtimeStatus->snapshot(), 'now' => time()])->withHeader('Cache-Control', 'no-store');
    }
}
