<?php
declare(strict_types=1);

namespace App\Http\Middleware;

use Loom\Middleware;
use Loom\Request;
use Loom\Response;

final class ParseJsonInput implements Middleware
{
    public function handle(Request $request, callable $next): Response
    {
        return $next($request->withAttribute('input', $request->json()));
    }
}
