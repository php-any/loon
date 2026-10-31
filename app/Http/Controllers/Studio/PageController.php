<?php
declare(strict_types=1);

namespace App\Http\Controllers\Studio;

use Loom\Action;
use Loom\Request;
use Loom\Response;
use App\Support\Showcase;

final class PageController implements Action
{
    public function __invoke(Request $request): Response
    {
        return Showcase::page('studio', '让每一个想法，向前一步');
    }
}
