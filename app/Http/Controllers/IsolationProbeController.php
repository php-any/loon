<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use Loom\Action;
use Loom\Request;
use Loom\Response;

final class IsolationProbeController implements Action
{
    private static int $visits = 0;
    public function __invoke(Request $request): Response
    {
        self::$visits++;
        $token = $request->header('x-loom-probe');
        if (strlen($token) > 80) { throw new \Loom\HttpError(422, 'Probe token too long'); }
        $GLOBALS['loom_probe_token'] = $token;
        return Response::json(['token' => $GLOBALS['loom_probe_token'], 'counter' => self::$visits])
            ->withHeader('Cache-Control', 'no-store');
    }
}
