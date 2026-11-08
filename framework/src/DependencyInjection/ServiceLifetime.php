<?php

declare(strict_types=1);

namespace Loom;

/**
 * 服务生命周期，对应 ASP.NET Core 的 ServiceLifetime。
 *
 * - Singleton：根容器创建一次，整个应用共用。不能依赖 Scoped 服务。
 * - Scoped：每个 ServiceScope 创建一次。一次 HTTP 请求、TCP 消息、gRPC 调用、命令、队列或定时器节拍各有一个作用域。
 * - Transient：每次解析都创建新对象。
 */
enum ServiceLifetime: string
{
    case Singleton = 'singleton';
    case Scoped = 'scoped';
    case Transient = 'transient';
}
