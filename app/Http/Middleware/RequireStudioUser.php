<?php
declare(strict_types=1);

namespace App\Http\Middleware;

use Loom\Auth;
use Loom\Middleware;
use Loom\Request;
use Loom\Response;

final class RequireStudioUser implements Middleware
{
    public function handle(Request $request, callable $next): Response
    {
        $user = Auth::guard();
        return $next($request->withAttribute('user', $user));
    }
}
