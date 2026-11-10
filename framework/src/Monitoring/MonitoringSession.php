<?php

declare(strict_types=1);

namespace Loom\Monitoring;

/**
 * 一次工作单元结束前的会话快照，供 filter / tag 与持久化使用。
 */
final class MonitoringSession
{
    /**
     * @param array<string, scalar|null> $tags
     * @param list<array<string, mixed>> $entries
     */
    public function __construct(
        public readonly string $channel,
        public readonly array $tags,
        public readonly ?string $correlationId,
        public readonly float $durationMs,
        public readonly array $entries,
    ) {
    }

    /** @return array<string, scalar|null> */
    public function mergedTags(array $extra): array
    {
        return array_merge($this->tags, $extra);
    }
}
