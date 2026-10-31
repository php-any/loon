<?php
declare(strict_types=1);

namespace App\Http\Controllers\Studio;

use Loom\Action;
use Loom\Request;
use Loom\Response;
use Loom\Auth;

final class LoginController implements Action
{
    public function __invoke(Request $request): Response
    {
        $input = $request->attribute('input')->only(['email', 'password']);
        $session = Auth::login($input->string('email', 3, 254), $input->string('password', 10, 72));
        return Response::json(['csrf' => $session['csrf']])->withHeader('Set-Cookie', $session['cookie']);
    }
}
