<?php

declare(strict_types=1);

/**
 * HTTP 与 WebSocket 共用的服务入口，执行 loom.php serve 时使用。
 * 静态文件由 public 目录提供，动态请求交给 entry 返回的应用处理。
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
    /**
     * PHP 应用入口路径，类型为字符串；相对于项目根目录，也支持绝对路径。
     * 默认入口注册模块服务、注解路由、请求日志中间件并调用显式绑定的 Loom\Http\RouteProvider。
     * 启动检查与每次动态请求都会加载入口；必须返回 Loom\Application 实例。
     * 自定义脚本可访问 $scope、$container、$config。
     * 示例：'entry' => 'bootstrap/http.php'（需先创建脚本并返回应用实例）。
     */
    'entry' => 'framework/bootstrap/http.php',
];
