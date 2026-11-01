<?php

declare(strict_types=1);

namespace Fixtures\Http;

use Loom\Action;
use Loom\Request;
use Loom\Response;

final class IsolationProbeController implements Action
{
    private int $visits = 0;
    private \Loom\RequestContext $context;
    public function __construct(\Loom\RequestContext $context)
    {
        $this->context = $context;
    }
    public function __invoke(Request $request): Response
    {
        $this->visits++;
        $token = $request->header('x-loom-probe');
        if (strlen($token) > 80) {
            throw new \Loom\HttpError(422, 'Probe token too long');
        }
        usleep(10000);
        return Response::json(['token' => $this->context->current()->header('x-loom-probe'), 'counter' => $this->visits])
            ->withHeader('Cache-Control', 'no-store');
    }
}
