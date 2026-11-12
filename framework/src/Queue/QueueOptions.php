<?php

declare(strict_types=1);

namespace Loom;

/**
 * 队列选项，对应 config/queue.php。
 */
final class QueueOptions
{
    public string $default = 'default';

    /**
     * @var array<string, array{driver?: string, connection?: string, name?: string}>
     */
    public array $connections = [
        'default' => [
            'driver' => 'redis',
            'connection' => 'default',
            'name' => '',
        ],
    ];

    /**
     * @return array{driver: string, connection: string, name: string}
     */
    public function connectionProfile(?string $name = null): array
    {
        $name = $name ?? $this->default;
        if ($name === '') {
            throw new \LogicException('Queue connection name must not be empty');
        }
        $config = $this->connections[$name] ?? null;
        if (!is_array($config)) {
            throw new \LogicException('Unknown queue connection: ' . $name);
        }
        $driver = (string)($config['driver'] ?? '');
        if ($driver === '') {
            throw new \LogicException('Queue connection "' . $name . '" has no driver');
        }
        return [
            'driver' => $driver,
            'connection' => (string)($config['connection'] ?? $this->default),
            'name' => (string)($config['name'] ?? ''),
        ];
    }
}
