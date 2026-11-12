<?php

declare(strict_types=1);

namespace Loom;

/**
 * Redis 命名连接选项，对应 config/redis.php。
 */
final class RedisOptions
{
    public string $default = 'default';

    /**
     * @var array<string, array{url?: string, prefix?: string}>
     */
    public array $connections = [
        'default' => [
            'url' => 'redis://127.0.0.1:6379/0',
            'prefix' => 'loom:',
        ],
    ];

    /**
     * @return array{url: string, prefix: string}
     */
    public function resolve(string $name): array
    {
        $config = $this->connections[$name] ?? null;
        if (!is_array($config)) {
            throw new \LogicException('Unknown Redis connection: ' . $name);
        }
        $url = (string)($config['url'] ?? '');
        if ($url === '') {
            $url = getenv('LOOM_REDIS_URL') ?: 'redis://127.0.0.1:6379/0';
        }
        $prefix = (string)($config['prefix'] ?? '');
        if ($prefix === '') {
            $envPrefix = getenv('LOOM_REDIS_PREFIX');
            $prefix = ($envPrefix === false || $envPrefix === '') ? 'loom:' : $envPrefix;
        }
        return ['url' => $url, 'prefix' => $prefix];
    }

    public static function fromEnvironment(): self
    {
        $options = new self();
        $envPrefix = getenv('LOOM_REDIS_PREFIX');
        $options->connections['default'] = [
            'url' => getenv('LOOM_REDIS_URL') ?: 'redis://127.0.0.1:6379/0',
            'prefix' => ($envPrefix === false || $envPrefix === '') ? 'loom:' : $envPrefix,
        ];
        return $options;
    }
}
