<?php

declare(strict_types=1);

namespace Loom;

/**
 * HTTP 监听使用的选项，对应 config/http.php。ApplicationBuilder 用 configure(HttpServerOptions::class, 'http') 绑定。
 *
 * @example $http = $services->get(HttpServerOptions::class);
 */
final class HttpServerOptions
{
    /**
     * 监听地址，形式为主机:端口。
     */
    public string $address;
}
