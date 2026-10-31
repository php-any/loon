<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use Loom\Action;
use Loom\Request;
use Loom\Response;
use App\Support\Showcase;

final class HomeController implements Action
{
    public function __invoke(Request $request): Response
    {
        return Showcase::page('home', '让想法，优雅成形');
    }
}
