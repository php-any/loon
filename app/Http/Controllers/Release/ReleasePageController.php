<?php

declare(strict_types=1);

namespace App\Http\Controllers\Release;

use App\Presentation\SitePages;
use Loom\Request;
use Loom\Response;

#[\Loom\Attributes\Controller]
final class ReleasePageController
{
    #[\Loom\Attributes\Route('GET', '/demo')]
    public function release(Request $request): Response
    {
        return SitePages::page('release', '发布准备室 · 端到端业务体验');
    }

    #[\Loom\Attributes\Route('GET', '/demo/protocols')]
    #[\Loom\Attributes\Route('GET', '/demo/workspace', 'studio')]
    #[\Loom\Attributes\Route('GET', '/demo/playground')]
    #[\Loom\Attributes\Route('GET', '/demo/welcome')]
    #[\Loom\Attributes\Route('GET', '/studio')]
    #[\Loom\Attributes\Route('GET', '/playground')]
    #[\Loom\Attributes\Route('GET', '/welcome')]
    #[\Loom\Attributes\Route('GET', '/demo/')]
    public function legacy(Request $request): Response
    {
        return (new Response('', 302))->withHeader('Location', '/demo');
    }
}
