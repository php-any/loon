<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Loom\Action;
use Loom\Request;
use Loom\Response;
use App\Presentation\SitePages;

#[\Loom\Attributes\Controller]
#[\Loom\Attributes\Route('GET', '/', '', [])]
final class HomeController implements Action
{
    public function __invoke(Request $request): Response
    {
        return SitePages::page('home', '从一次体验，开启更多可能');
    }
}
