<?php

declare(strict_types=1);

namespace App\Http;

use Loom\Http\RouteProvider;
use Loom\Router;

final class ApplicationRoutes implements RouteProvider
{
    public function register(Router $routes): void
    {
        // HTTP 和 WebSocket 路由目前由控制器的 Route 注解声明。
        // 手动扩展可使用 $routes->route(...) 或 $routes->websocket(...)。
    }
}
