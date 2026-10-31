<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use Loom\Action;
use Loom\Request;
use Loom\Response;
use App\Support\Showcase;

final class WelcomeController implements Action
{
    public function __invoke(Request $request): Response
    {
        $name = $request->query('name')[0] ?? '访客';
        return Showcase::page('welcome', '一份来自服务端的问候', ['name' => $name]);
    }
}
