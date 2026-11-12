<?php

declare(strict_types=1);

namespace Loom;

/**
 * 队列投递后端：Queue 实际调用的最小面。内置实现为 Redis Stream（RedisQueue）。
 */
interface QueueBackend
{
    public function enqueue(array $envelope, int $available, int $now): string;

    public function claim(int $now): ?array;

    public function ack(string $messageId): void;

    public function requeue(string $messageId, array $envelope, int $available, int $now): void;

    public function dead(string $messageId, array $envelope): void;
}
