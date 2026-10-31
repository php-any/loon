<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use Loom\Action;
use Loom\Request;
use Loom\Response;
use App\Support\Showcase;

final class GuideController implements Action
{
    public function __invoke(Request $request): Response { return Showcase::page('guide', '从第一行，到一个完整响应'); }
}
