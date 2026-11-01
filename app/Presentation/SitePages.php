<?php

declare(strict_types=1);

namespace App\Presentation;

use Loom\Response;
use Loom\View;

final class SitePages
{
    public static function page(string $page, string $title, array $data = []): Response
    {
        // 页面名称由应用代码指定，不能直接使用 HTTP 参数。
        $templates = ['home' => 'official/pages/home', 'customer' => 'official/pages/customer', 'guide' => 'official/guide/index', 'release' => 'demo/pages/release'];
        if (!isset($templates[$page])) {
            throw new \LogicException('Unknown page: ' . $page);
        }
        $content = View::render(dirname(__DIR__, 2) . '/resources/views/' . $templates[$page] . '.php', $data);
        $site = in_array($page, ['home', 'guide', 'customer'], true) ? 'official' : 'demo';
        return Response::html(View::render(dirname(__DIR__, 2) . '/resources/views/' . $site . '/layout.php', [
            'page' => $page, 'title' => $title, 'content' => $content,
        ]))->withHeader('Content-Security-Policy', "default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; connect-src 'self'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'");
    }
}

