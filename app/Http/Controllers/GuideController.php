<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Loom\Request;
use Loom\Response;
use App\Presentation\SitePages;

#[\Net\Annotation\Controller]
final class GuideController
{
    #[\Net\Annotation\GetMapping('/guide')]
    public function page(Request $request): Response
    {
        return SitePages::page('guide', '从第一行，到一个完整响应');
    }
}
