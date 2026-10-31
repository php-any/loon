<?php
declare(strict_types=1);

namespace App\Http\Controllers\Studio;

use Loom\Action;
use Loom\Request;
use Loom\Response;
use Loom\Auth;
use Loom\Schedule;

final class PreviewCronController implements Action
{
    public function __invoke(Request $request): Response
    {
        Auth::guard(); $input = $request->json()->only(['expression', 'timezone']);
        return Response::json(['times' => Schedule::preview($input->string('expression', 1, 100), $input->string('timezone', 1, 80))]);
    }
}
