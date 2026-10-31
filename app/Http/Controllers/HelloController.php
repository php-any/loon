<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use Loom\Action;
use Loom\Request;
use Loom\Response;

final class HelloController implements Action
{
    public function __invoke(Request $request): Response
    {
        return Response::json(['message' => 'Hello, ' . $request->parameter('name')]);
    }
}
