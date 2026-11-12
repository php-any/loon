<?php

declare(strict_types=1);

namespace Loom\Attributes;

/**
 * 语言映射未覆盖的 HTTP 路由：PATCH/HEAD/OPTIONS、命名路由、方法级中间件、WebSocket。
 *
 * 构造参数：
 * - $method（string）：HTTP 方法。
 * - $path（string）：相对类前缀的路径。
 * - $name（string）：路由名。默认空字符串。
 * - $middleware（array）：方法级中间件。默认空数组。
 * - $websocket（bool）：是否按 WebSocket 登记。默认 false。
 *
 * 返回值：使用 new 构造时得到 HttpRoute。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example new HttpRoute('PATCH', '/items/{id}', 'items.update', [AuthMiddleware::class]);
 */
#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
final class HttpRoute
{
    public function __construct(
        public string $method,
        public string $path,
        public string $name = '',
        public array $middleware = [],
        public bool $websocket = false,
    ) {}
}
