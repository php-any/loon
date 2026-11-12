<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Loom\Request;
use Loom\Response;
use App\Presentation\SitePages;

#[\Loom\Attributes\Controller]
final class GuideController
{
    #[\Loom\Attributes\Route('GET', '/guide', '', [])]
    public function page(Request $request): Response
    {
        return SitePages::page('guide', '从第一行，到一个完整响应');
    }
}
