<?php

declare(strict_types=1);

namespace Loom\Attributes;

/**
 * 标在类上，声明同步事件监听器。
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::IS_REPEATABLE)]
final class Listens
{
    public function __construct(public string $event, public string $method = 'handle')
    {
    }
}
