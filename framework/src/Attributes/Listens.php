<?php

declare(strict_types=1);

namespace Loom\Attributes;

#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::IS_REPEATABLE)]
final class Listens
{
    public function __construct(
        public string $event,
        public string $method = 'handle',
        public bool $queued = false,
    ) {
    }
}
