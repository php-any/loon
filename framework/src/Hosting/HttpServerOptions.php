<?php

declare(strict_types=1);

namespace Loom;

/**
 * HTTP 监听使用的选项，对应 config/http.php。
 *
 * 构造参数：无，本类型未声明构造参数。属性由 Options 从 http 配置节写入。
 *
 * 返回值：使用 new 构造时得到 HttpServerOptions 实例。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $http = $services->get(HttpServerOptions::class);
 */
final class HttpServerOptions
{
    /**
     * 监听地址，形式为主机:端口。
     */
    public string $address;

    /**
     * 返回 Application 的入口脚本，相对项目根目录或绝对路径。
     */
    public string $entry;
}
