<?php

declare(strict_types=1);

namespace App\Http\Controllers\System;

use Loom\Request;
use Loom\Response;
use Loom\RuntimeStatus;

#[\Net\Annotation\Controller]
final class RuntimeController
{
    private \Loom\RuntimeStatus $runtimeStatus;

    public function __construct(\Loom\RuntimeStatus $runtimeStatus)
    {
        $this->runtimeStatus = $runtimeStatus;
    }

    #[\Net\Annotation\GetMapping('/api/studio/runtime')]
    public function status(Request $request): Response
    {
        return Response::json(['services' => $this->runtimeStatus->snapshot(), 'now' => time()])->withHeader('Cache-Control', 'no-store');
    }
}
