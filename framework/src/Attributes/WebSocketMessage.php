<?php

declare(strict_types=1);

namespace Loom\Attributes;

/** 按客户端 JSON 消息字段 type 分发到处理器方法。 */
#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
final class WebSocketMessage
{
    public function __construct(public string $type) {}
}
