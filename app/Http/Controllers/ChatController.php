<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Loom\Request;
use Loom\Response;

#[\Net\Annotation\Controller]
final class ChatController
{
    private \Loom\Config $config;

    public function __construct(\Loom\Config $config)
    {
        $this->config = $config;
    }

    #[\Net\Annotation\GetMapping('/chat')]
    public function page(Request $request): Response
    {
        return $this->entry();
    }

    #[\Net\Annotation\GetMapping('/chat/{path}')]
    public function pageWithPath(Request $request): Response
    {
        return $this->entry();
    }

    private function entry(): Response
    {
        $entry = $this->config->path('public/chat/index.html');
        if (!is_file($entry)) {
            return Response::html('聊天前端尚未构建。请在 frontend/chat 执行 npm install 与 npm run build；开发环境可运行 scripts/dev-chat.ps1。', 503);
        }
        $nonce = bin2hex(random_bytes(24));
        $html = str_replace('<script', '<script nonce="' . $nonce . '"', file_get_contents($entry));
        return Response::html($html)
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('Content-Security-Policy', "default-src 'self'; script-src 'self' 'nonce-$nonce'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self' data:; connect-src 'self'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'");
    }
}
