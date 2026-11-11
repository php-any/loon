<?php

declare(strict_types=1);

namespace Loom\Events;

/**
 * 可入队事件：负载仅标量/数组/null，不得包含 Model。
 */
interface QueueableEvent
{
  /**
   * @return array<string, mixed>
   */
    public function toQueue(): array;

  /**
   * @param array<string, mixed> $data
   */
    public static function fromQueue(array $data): static;
}
