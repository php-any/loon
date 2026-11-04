<?php

declare(strict_types=1);

namespace Loom;

/**
 * 保存 http、websocket、tcp、grpc 四组默认整数限制。limits 用配置里的同名项覆盖默认值。
 *
 * 构造参数：
 * - $config（Config）：limits 时读取 network 下对应协议的那一节。
 *
 * 返回值：使用 new 构造时得到尚未读取限制的 Network。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $network = new Network($config);
 */
final class Network
{
    private \Loom\Config $config;

    /**
     * 保存配置对象。构造时不读取、也不校验 network 配置。
     *
     * @param \Loom\Config $config 之后按 network.{协议} 读取覆盖值的配置。
     * @return void 无直接返回值。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $network = new Network($config);
     */
    public function __construct(\Loom\Config $config)
    {
        $this->config = $config;
    }

    private const DEFAULTS = [
        'http' => ['maxConnections' => 256, 'maxRequests' => 128, 'maxBodyBytes' => 1048576, 'maxHeaderBytes' => 16384, 'readHeaderTimeoutMs' => 5000, 'readTimeoutMs' => 10000, 'writeTimeoutMs' => 10000, 'idleTimeoutMs' => 30000, 'requestTimeoutMs' => 10000],
        'websocket' => ['maxMessageBytes' => 1048576, 'readTimeoutMs' => 10000, 'writeTimeoutMs' => 10000],
        'tcp' => ['maxConnections' => 128, 'readTimeoutMs' => 10000, 'writeTimeoutMs' => 10000, 'connectionTimeoutMs' => 60000, 'maxFrameBytes' => 1048576, 'maxResponseBytes' => 4194304, 'maxMessages' => 100],
        'grpc' => ['maxConnections' => 128, 'maxConcurrentStreams' => 32, 'maxCalls' => 128, 'maxRecvBytes' => 1048576, 'maxSendBytes' => 4194304, 'maxHeaderBytes' => 16384, 'requestTimeoutMs' => 10000, 'connectionTimeoutMs' => 5000, 'idleTimeoutMs' => 30000, 'maxConnectionAgeMs' => 300000],
    ];
    /**
     * 先复制该协议的默认限制，再用 network.{协议} 里的值覆盖。配置节缺失时按空数组处理。
     * 未知协议会在读取默认表时触发未定义索引。覆盖项必须是已有的键，并且是 1 到 1073741824 的整数，否则抛出 InvalidArgumentException。
     *
     * @param string $protocol http、websocket、tcp、grpc 之一。
     * @return array 限制名到整数。键名以 Ms 结尾的是毫秒，以 Bytes 结尾的是字节。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $limits = $network->limits('http');
     */
    public function limits(string $protocol): array
    {
        $limits = self::DEFAULTS[$protocol];
        foreach ($this->config->get('network.' . $protocol, []) as $name => $value) {
            if (!array_key_exists($name, $limits) || !is_int($value) || $value < 1 || $value > 1073741824) {
                throw new \InvalidArgumentException('Invalid network limit: ' . $protocol . '.' . $name);
            }
            $limits[$name] = $value;
        }
        return $limits;
    }
}
