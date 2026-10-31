<?php
declare(strict_types=1);

namespace App\Support;

use Loom\Response;
use Loom\View;

final class Showcase
{
    public static function page(string $page, string $title, array $data = []): Response
    {
        // 页面名称由应用代码指定，不能直接使用 HTTP 参数。
        $content = View::render(dirname(__DIR__, 2) . '/resources/views/' . $page . '.php', $data);
        return Response::html(View::render(dirname(__DIR__, 2) . '/resources/views/layout.php', [
            'page' => $page, 'title' => $title, 'content' => $content,
        ]))->withHeader('Content-Security-Policy', "default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; connect-src 'self'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'");
    }
}
