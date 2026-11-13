<?php

declare(strict_types=1);

namespace Loom\WebSocket;

/**
 * 跨进程 WebSocket 频道广播，底层使用 Redis Stream。
 */
#[\Container\Annotation\Singleton]
final class Hub
{
    private \Loom\Config $config;

    public function __construct(\Loom\Config $config)
    {
        $this->config = $config;
    }

    public function channelKey(string $channel): string
    {
        $dsn = hash('sha256', (new \Loom\Database($this->config))->pdoDsn());
        return \Loom\Redis::key('websocket:' . $dsn . ':' . $channel);
    }

    /**
     * @param array<string, mixed>|string $payload
     */
    public function send(string $channel, array|string $payload): void
    {
        $body = is_string($payload) ? $payload : json_encode($payload, JSON_THROW_ON_ERROR);
        \Loom\Redis::using(function ($client) use ($channel, $body): void {
            $key = $this->channelKey($channel);
            \Loom\Redis::execute($client, ['XADD', $key, 'MAXLEN', '~', '1000', '*', 'payload', $body]);
            \Loom\Redis::execute($client, ['EXPIRE', $key, '86400']);
        });
        try {
            $monitoring = \Loom\Monitoring\ActiveMonitoring::current();
            if ($monitoring !== null && $monitoring->isActive()) {
                $monitoring->recordEvent('websocket.broadcast', ['channel' => $channel]);
            }
        } catch (\Throwable) {
        }
    }

    public function tail(\Redis $client, string $channel): string
    {
        $events = \Loom\Redis::execute($client, ['XREVRANGE', $this->channelKey($channel), '+', '-', 'COUNT', '1']);
        return $events[0][0] ?? '0-0';
    }

    /**
     * @param array<string, string> $channels 逻辑频道名 => Stream 游标
     * @return list<array{channel: string, payload: string, cursor: string}>
     */
    public function read(\Redis $client, array $channels, int $blockMs = 0, int $count = 100): array
    {
        if ($channels === []) {
            return [];
        }
        $logical = [];
        $keys = [];
        $cursors = [];
        foreach ($channels as $channel => $cursor) {
            $key = $this->channelKey($channel);
            $logical[$key] = $channel;
            $keys[] = $key;
            $cursors[] = $cursor;
        }
        $command = ['XREAD'];
        if ($blockMs > 0) {
            $command[] = 'BLOCK';
            $command[] = (string)$blockMs;
        }
        $command[] = 'COUNT';
        $command[] = (string)$count;
        $command[] = 'STREAMS';
        array_push($command, ...$keys);
        array_push($command, ...$cursors);
        $streams = \Loom\Redis::execute($client, $command);
        if ($streams === null || $streams === []) {
            return [];
        }
        $out = [];
        foreach ($streams as $stream) {
            $key = (string)$stream[0];
            $channel = $logical[$key] ?? $key;
            foreach ($stream[1] as $event) {
                $cursor = (string)$event[0];
                $payload = '';
                for ($i = 1; $i < count($event); $i += 2) {
                    if (($event[$i] ?? '') === 'payload') {
                        $payload = (string)($event[$i + 1] ?? '');
                    }
                }
                $out[] = ['channel' => $channel, 'payload' => $payload, 'cursor' => $cursor];
            }
        }
        return $out;
    }
}
