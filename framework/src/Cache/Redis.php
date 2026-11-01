<?php

declare(strict_types=1);

namespace Loom;

/**
 * 按显式操作打开并关闭 Redis 连接，统一前缀、认证、命令错误及 nil 返回值。
 *
 * 构造参数：无，本类型未声明构造参数。
 *
 * 返回值：使用 new 构造时得到 Redis 实例；注册到容器时由容器管理实例的创建与复用。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $value = Redis::command(['GET', Redis::key('example')]);
 */
final class Redis
{
    /**
     * 在业务键后缀前添加 LOOM_REDIS_PREFIX，未设置时使用 loom:。
     *
     * @param string $suffix 不含环境前缀的业务键后缀。
     * @return string 带环境前缀的完整 Redis 键。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $key = Redis::key('session:demo');
     */
    public static function key(string $suffix): string
    {
        $prefix = getenv('LOOM_REDIS_PREFIX');
        return ($prefix === false || $prefix === '' ? 'loom:' : $prefix) . $suffix;
    }
    /**
     * 为一条原始 Redis 命令创建短生命周期连接并执行。
     *
     * @param array $args 原始命令及其参数列表；eval 中为 ARGV 对应的脚本参数列表。
     * @return mixed 规范化后的 Redis 返回值，nil 表示为 null。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $value = Redis::command(['GET', Redis::key('example')]);
     */
    public static function command(array $args): mixed
    {
        return self::using(fn ($client) => self::execute($client, $args));
    }
    // 连接属于当前显式作用域，不属于复制的请求或定时器静态缓存。
    /**
     * 解析 redis/rediss URL，连接、认证、选择数据库，执行回调后始终关闭连接。
     *
     * @param callable $operation 接收已连接原生 Redis 客户端的回调，不应将客户端带出作用域。
     * @return mixed operation 返回值；RedisException 转为 HTTP 503。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $value = Redis::using(fn (\Redis $client) => Redis::execute($client, ['PING']));
     */
    public static function using(callable $operation): mixed
    {
        $client = new \Redis();
        try {
            $url = getenv('LOOM_REDIS_URL') ?: 'redis://127.0.0.1:6379/0';
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
     * @param \Redis $client 已连接并选择数据库的原生 Redis 客户端。
     * @param array $args 原始命令及其参数列表；eval 中为 ARGV 对应的脚本参数列表。
     * @return mixed Redis 标量或数组结果，false 被转换为 null。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $value = Redis::execute($client, ['GET', Redis::key('example')]);
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
     * 内部调用示例（仅类内使用）：
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
     * @param \Redis $client 已连接并选择数据库的原生 Redis 客户端。
     * @param array $commands 每项为命令名称及参数组成的数组。
     * @return array 逐条规范化后的结果列表。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $results = Redis::pipeline($client, [['PING'], ['GET', Redis::key('example')]]);
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
     * @param string $script 要通过 EVAL 执行的可信 Lua 源码。
     * @param array $keys 脚本访问的完整 Redis 键列表，映射到 KEYS。
     * @param array $args 原始命令及其参数列表；eval 中为 ARGV 对应的脚本参数列表。默认值为 []。
     * @return mixed 脚本的规范化 Redis 返回值。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $value = Redis::eval('return redis.call("GET", KEYS[1])', [Redis::key('example')]);
     */
    public static function eval(string $script, array $keys, array $args = []): mixed
    {
        return self::command(array_merge(['EVAL', $script, (string)count($keys)], $keys, $args));
    }
}
