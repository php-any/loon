<?php

declare(strict_types=1);

namespace Loom;

/**
 * 每次操作单独连接 Redis，结束时关闭。nil 收成 null；0、空字符串和空数组保留。连接或命令失败抛 HTTP 503。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $redis = new Redis();
 */
final class Redis
{
    private static ?RedisOptions $options = null;

    /**
     * @return ?RedisOptions 此前绑定的选项，供测试恢复。
     */
    public static function bindOptions(?RedisOptions $options): ?RedisOptions
    {
        $previous = self::$options;
        self::$options = $options;
        return $previous;
    }

    /**
     * 在业务键后缀前添加连接上的前缀，默认连接未设置时使用 loom:。
     *
     * @param string $suffix 不含环境前缀的业务键后缀。
     * @param ?string $connection 命名连接；null 表示 default。
     */
    public static function key(string $suffix, ?string $connection = null): string
    {
        $resolved = self::resolvedConnection($connection);
        return $resolved['prefix'] . $suffix;
    }
    /**
     * 为一条原始 Redis 命令创建短生命周期连接并执行。
     *
     * @param array $args 第一项是命令名，后面是这条命令自己的参数。
     * @return mixed 规范化后的返回值；Redis 的 nil 或 false 为 null。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $value = Redis::command(['PING']);
     */
    public static function command(array $args, ?string $connection = null): mixed
    {
        return self::using(fn ($client) => self::execute($client, $args), $connection);
    }
    // 连接属于当前显式作用域，不属于复制的请求或定时器静态缓存。
    /**
     * 解析 redis/rediss URL，连接、认证、选择数据库，执行回调后始终关闭连接。
     *
     * @param callable $operation 接收这一次连接的回调。不要把客户端留到回调外面。
     * @return mixed 回调的返回值。URL 非法抛 LogicException，认证失败抛 RuntimeException，RedisException 变成 HTTP 503。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $value = Redis::using(fn (\Redis $client) => $client);
     */
    public static function using(callable $operation, ?string $connection = null): mixed
    {
        $client = new \Redis();
        try {
            $url = self::resolvedConnection($connection)['url'];
            $parts = parse_url($url);
            if (!is_array($parts) || !in_array($parts['scheme'] ?? '', ['redis', 'rediss'], true) || empty($parts['host']) || isset($parts['query']) || isset($parts['fragment'])) {
                throw new \LogicException('Invalid Redis configuration');
            }
            $host = ($parts['scheme'] === 'rediss' ? 'tls://' : '') . trim($parts['host'], '[]');
            $client->connect($host, (int)($parts['port'] ?? 6379), 5.0, null, 0, 5.0);
            if (isset($parts['user']) || isset($parts['pass'])) {
                $password = rawurldecode($parts['pass'] ?? '');
                $user = rawurldecode($parts['user'] ?? '');
                if (!$client->auth($user === '' ? $password : [$user, $password])) {
                    throw new \RuntimeException('Redis authentication failed');
                }
            }
            $database = ltrim($parts['path'] ?? '/0', '/');
            if ($database === '') { $database = '0'; }
            if (!preg_match('/^[0-9]+$/D', $database) || !$client->select((int)$database)) {
                throw new \LogicException('Invalid Redis database');
            }
            return $operation($client);
        } catch (\RedisException $error) {
            throw new HttpError(503, 'Redis 服务不可用，请检查服务端配置');
        } finally {
            $client->close();
        }
    }
    /**
     * 在已有连接执行原始命令，检查服务端错误并规范 nil。
     *
     * @param \Redis $client 已经连上并选好库的客户端。
     * @param array $args 交给 rawCommand 的命令名和参数，按顺序展开。
     * @return mixed 标量或数组。服务端报错抛 HTTP 503；false 收成 null。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $value = Redis::execute($client, ['PING']);
     */
    public static function execute(\Redis $client, array $args): mixed
    {
        $client->clearLastError();
        $value = $client->rawCommand(...$args);
        if ($client->getLastError()) {
            throw new HttpError(503, 'Redis 命令执行失败');
        }
        // 保留 Loom 现有的 nil 契约，同时保留 0、空字符串和空数组。
        return self::normalize($value);
    }
    /**
     * 递归把 Redis 的 false/nil 转为 null，保留 0、空字符串和空数组。
     *
     * @param mixed $value 原生 Redis 返回值，可含嵌套数组。
     * @return mixed 规范化后的原类型值或 null。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $value = self::normalize($value);
     */
    private static function normalize(mixed $value): mixed
    {
        if ($value === false) { return null; }
        if (is_array($value)) {
            $items=[];
            foreach ($value as $item) { $items[]=self::normalize($item); }
            return $items;
        }
        return $value;
    }
    /**
     * 在已有连接批量提交命令并按提交顺序返回结果，失败时抛出 HTTP 503。
     *
     * @param \Redis $client 已经连上并选好库的客户端。
     * @param array $commands 要按顺序提交的命令，每项是命令名和它的参数。
     * @return array 与提交顺序一致的规范化结果。exec 失败或有错误时抛 HTTP 503。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $results = Redis::pipeline($client, [['PING']]);
     */
    public static function pipeline(\Redis $client, array $commands): array
    {
        $client->clearLastError();
        $client->pipeline();
        foreach ($commands as $command) {
            $client->rawCommand(...$command);
        }
        $results = $client->exec();
        if ($results === false || $client->getLastError()) {
            throw new HttpError(503, 'Redis 批处理失败');
        }
        return self::normalize($results);
    }
    /**
     * 构造带键数量的 EVAL 命令并使用独立连接执行 Lua 脚本。
     *
     * @param string $script 这次 EVAL 要跑的 Lua 源码。
     * @param array $keys 映射到 KEYS 的完整键。
     * @param array $args 映射到 ARGV 的参数，接在键后面。默认值为 []。
     * @return mixed 脚本的返回值；nil 为 null。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $value = Redis::eval('return KEYS[1]', ['loom:example']);
     */
    public static function eval(string $script, array $keys, array $args = [], ?string $connection = null): mixed
    {
        return self::command(array_merge(['EVAL', $script, (string)count($keys)], $keys, $args), $connection);
    }

    /**
     * @return array{url: string, prefix: string}
     */
    private static function resolvedConnection(?string $connection): array
    {
        $options = self::$options ?? RedisOptions::fromEnvironment();
        $name = $connection ?? '';
        if ($name === '') {
            $name = $options->default;
        }
        return $options->resolve($name);
    }
}
