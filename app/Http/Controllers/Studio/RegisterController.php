<?php
declare(strict_types=1);

namespace App\Http\Controllers\Studio;

use Loom\Action;
use Loom\Request;
use Loom\Response;
use Loom\Auth;

final class RegisterController implements Action
{
    public function __invoke(Request $request): Response
    {
        $input = $request->json()->only(['name', 'email', 'password']);
        $session = Auth::register($input->string('name', 1, 80), $input->string('email', 3, 254), $input->string('password', 10, 72));
        return Response::json(['csrf' => $session['csrf']], 201)->withHeader('Set-Cookie', $session['cookie']);
    }
}
