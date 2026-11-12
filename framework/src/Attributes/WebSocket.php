<?php

declare(strict_types=1);

namespace Loom\Attributes;

/**
 * WebSocket 升级路由：等价于 GET + websocket=true，不要与同方法上的语言 Mapping 或 HttpRoute 共存。
 *
 * 构造参数：
 * - $path（string）：相对类前缀的路径。
 * - $name（string）：路由名。默认空字符串。
 * - $middleware（array）：方法级中间件。默认空数组。
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final class WebSocket
{
    public function __construct(
        public string $path,
        public string $name = '',
        public array $middleware = [],
    ) {}
}
