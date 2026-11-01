<?php

declare(strict_types=1);

namespace Loom;

/**
 * 集中提供 HTTP、WebSocket、TCP、gRPC 的默认限制，并校验对应 network 配置覆盖值。
 *
 * 构造参数：
 * - $config（\Loom\Config）：项目配置实例，提供配置读取及项目路径解析。
 *
 * 返回值：使用 new 构造时得到 Network 实例；注册到容器时由容器管理实例的创建与复用。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $limits = (new Network($config))->limits('http');
 */
final class Network
{
    private \Loom\Config $config;

    /**
     * 初始化 Network 实例及其依赖。集中提供 HTTP、WebSocket、TCP、gRPC 的默认限制，并校验对应 network 配置覆盖值。
     *
     * @param \Loom\Config $config 项目配置实例，提供配置读取及项目路径解析。
     * @return void 无直接返回值；通过 new 表达式取得初始化后的实例。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $limits = (new Network($config))->limits('http');
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
     * 合并指定协议的默认限制与显式覆盖，拒绝未知键及非 1–1073741824 范围整数。
     *
     * @param string $protocol 支持的协议分组：http、websocket、tcp 或 grpc。
     * @return array 配置项到整数值的关联数组，Ms 单位为毫秒，Bytes 单位为字节。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $limits = $network->limits('websocket');
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
