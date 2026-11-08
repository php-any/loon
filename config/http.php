<?php

declare(strict_types=1);

/**
 * HTTP 与 WebSocket 共用的服务入口，执行 loom.php serve 时使用。
 * 静态文件由 public 目录提供，动态请求交给 loom.php 构建的应用，每个请求一个服务作用域。
 * 并发、请求体大小和超时位于 config/network.php，不在此处配置。
 * 配置加载后会缓存，修改后需重启 HTTP 服务。
 */
return [
    /**
     * 监听地址，类型为“主机:端口”字符串，不带 http://、路径或查询参数。
     * 优先使用 LOOM_ADDRESS，未设置或为空时为 127.0.0.1:18087。
     * 127.0.0.1 仅供本机连接；0.0.0.0 监听全部 IPv4 网卡；[::1] 为 IPv6 回环地址。
     * 示例：.env 中设置 LOOM_ADDRESS=0.0.0.0:8080，或直接填写 'address' => '127.0.0.1:8080'。
     * 根目录 .env 的同名值会覆盖进程环境变量；这里没有 HTTPS 证书配置项。
     */
    'address' => (getenv('LOOM_ADDRESS') ?: '127.0.0.1:18087'),
];
