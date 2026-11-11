<?php

declare(strict_types=1);

namespace Loom;

/**
 * Redis Stream 作为队列投递源：主 Stream、延迟有序集与死信 Stream，均在同一哈希槽。
 */
final class RedisQueue
{
    private string $cursor = '0-0';
    private ?string $consumer = null;

    public function __construct(private readonly \Loom\Config $config)
    {
    }

  /**
   * 每个 queue:work 进程一个消费名，避免共用 workers。
   */
    public function consumer(): string
    {
        if ($this->consumer === null) {
            $configured = getenv('LOOM_QUEUE_CONSUMER');
            $this->consumer = is_string($configured) && $configured !== ''
                ? $configured
                : 'worker-' . getmypid() . '-' . bin2hex(random_bytes(6));
        }
        return $this->consumer;
    }

  /**
   * @return array{0: string, 1: string, 2: string} [stream, delayed, dead]
   */
    public function keys(): array
    {
        $base = '{queue:' . hash('sha256', (new Database($this->config))->pdoDsn()) . '}';
        return [Redis::key($base . ':stream'), Redis::key($base . ':delayed'), Redis::key($base . ':dead')];
    }

    public function enqueue(array $envelope, int $available, int $now): string
    {
        $payload = json_encode($envelope, JSON_THROW_ON_ERROR);
        return Redis::using(function (\Redis $client) use ($payload, $available, $now): string {
            Redis::execute($client, $this->command('initialize', []));
            $result = Redis::execute($client, $this->command('enqueue', [$payload, (string)$available, (string)$now]));
            return is_string($result) ? $result : (string)$result;
        });
    }

    public function claim(int $now): ?array
    {
        return Redis::using(fn ($client) => $this->claimUsing($client, $now));
    }

    public function ack(string $messageId): void
    {
        Redis::command($this->command('ack', [$messageId]));
    }

    public function requeue(string $messageId, array $envelope, int $available, int $now): void
    {
        $payload = json_encode($envelope, JSON_THROW_ON_ERROR);
        Redis::command($this->command('requeue', [$messageId, $payload, (string)$available, (string)$now]));
    }

    public function dead(string $messageId, array $envelope): void
    {
        $payload = json_encode($envelope, JSON_THROW_ON_ERROR);
        Redis::command($this->command('dead', [$messageId, $payload]));
    }

    private function claimUsing(\Redis $client, int $now): ?array
    {
        Redis::execute($client, $this->command('initialize', []));
        $claimed = Redis::execute($client, $this->command('claim', [
            $this->consumer(),
            '60000',
            $this->cursor,
            (string)$now,
            '100',
        ]));
        $this->cursor = (string)$claimed[0];
        if (!isset($claimed[1], $claimed[2])) {
            return null;
        }
        $job = json_decode((string)$claimed[2], true, 64, JSON_THROW_ON_ERROR);
        $job['message_id'] = (string)$claimed[1];
        return $job;
    }

    private static function script(string $name): string
    {
        return file_get_contents(dirname(__DIR__, 2) . '/resources/queue/' . $name . '.lua');
    }

  /**
   * @return list<mixed>
   */
    private function command(string $name, array $args): array
    {
        return array_merge(['EVAL', self::script($name), '3'], $this->keys(), $args);
    }
}
