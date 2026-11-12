<?php

declare(strict_types=1);

namespace App\Http\Controllers\Release;

use App\Presentation\SitePages;
use Loom\Request;
use Loom\Response;

#[\Net\Annotation\Controller]
final class ReleasePageController
{
    #[\Net\Annotation\GetMapping('/demo')]
    public function release(Request $request): Response
    {
        return SitePages::page('release', '发布准备室 · 端到端业务体验');
    }

    #[\Loom\Attributes\HttpRoute('GET', '/demo/protocols')]
    #[\Loom\Attributes\HttpRoute('GET', '/demo/workspace', 'studio')]
    #[\Loom\Attributes\HttpRoute('GET', '/demo/playground')]
    #[\Loom\Attributes\HttpRoute('GET', '/demo/welcome')]
    #[\Loom\Attributes\HttpRoute('GET', '/studio')]
    #[\Loom\Attributes\HttpRoute('GET', '/playground')]
    #[\Loom\Attributes\HttpRoute('GET', '/welcome')]
    #[\Loom\Attributes\HttpRoute('GET', '/demo/')]
    public function legacy(Request $request): Response
    {
        return (new Response('', 302))->withHeader('Location', '/demo');
    }
}
