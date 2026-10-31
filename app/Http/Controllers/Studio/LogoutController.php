<?php
declare(strict_types=1);

namespace App\Http\Controllers\Studio;

use Loom\Action;
use Loom\Request;
use Loom\Response;
use Loom\Auth;

final class LogoutController implements Action
{
    public function __invoke(Request $request): Response
    {
        $session = Auth::logout();
        return Response::json(['ok' => true])->withHeader('Set-Cookie', $session['cookie']);
    }
}
