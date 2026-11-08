<?php

declare(strict_types=1);

/**
 * TCP JSON 行协议服务配置，执行 loom.php tcp:serve 时使用。
 * 客户端每条消息必须为 JSON 对象，并以换行符结束；服务端响应同样以换行符结束。
 * 连接数、超时、帧大小和单连接消息数在 config/network.php 的 tcp 分组配置。
 * 修改后需重启 TCP 服务。
 */
return [
    /**
     * 监听地址，类型为“主机:端口”字符串，不带 tcp://。
     * 优先读取 LOOM_TCP_ADDRESS，未设置或为空时为 127.0.0.1:18088。
     * 示例：LOOM_TCP_ADDRESS=0.0.0.0:9000；127.0.0.1 仅监听本机，0.0.0.0 监听全部 IPv4 网卡。
     * 根目录 .env 的同名值覆盖进程环境变量。
     */
    'address' => (getenv('LOOM_TCP_ADDRESS') ?: '127.0.0.1:18088'),
    /**
     * 业务处理器的完整类名，类型为字符串。每条消息在新的服务作用域里解析它，构造函数可注入服务。
     * 类需可自动加载，并提供 public function handle(array $message): array 方法。
     * 可使用类名字符串或 ::class，例如 'handler' => \App\Tcp\Handlers\ReleaseSnapshotHandler::class。
     * 扩展业务时可指向自行创建的 \App\Tcp\Handlers\OrderHandler::class。
     */
    'handler' => 'App\\Tcp\\Handlers\\ReleaseSnapshotHandler',
];
