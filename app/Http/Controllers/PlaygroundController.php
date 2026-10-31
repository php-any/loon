<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use Loom\Action;
use Loom\Request;
use Loom\Response;
use App\Support\Showcase;

final class PlaygroundController implements Action
{
    public function __invoke(Request $request): Response { return Showcase::page('playground', '把好奇心交给一次请求'); }
}
