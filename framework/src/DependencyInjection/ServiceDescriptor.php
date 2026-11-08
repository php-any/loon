<?php

declare(strict_types=1);

namespace Loom;

/**
 * 一条服务登记：标识、生命周期，以及实现类名、工厂或现成实例三者之一。对应 ASP.NET Core 的 ServiceDescriptor。
 *
 * 实现是字符串时按类名构造，构造参数按类型从容器解析；是可调用对象时作为工厂，参数是发起解析的 ServiceResolver。
 * 现成实例只能是 Singleton，容器不负责释放它。
 *
 * @example $descriptor = ServiceDescriptor::scoped(Repository::class, SqlRepository::class);
 */
final class ServiceDescriptor
{
    /**
     * @param string $id 服务标识，通常是类名或接口名。
     * @param ServiceLifetime $lifetime 生命周期。
     * @param mixed $implementation 实现类名或工厂。null 表示按 $id 构造。
     * @param ?object $instance 现成实例。非 null 时生命周期必须是 Singleton。
     */
    public function __construct(
        public readonly string $id,
        public readonly ServiceLifetime $lifetime,
        public readonly mixed $implementation = null,
        public readonly ?object $instance = null,
    ) {
        if ($id === '') {
            throw new \InvalidArgumentException('Service id must not be empty');
        }
        if ($instance !== null && $lifetime !== ServiceLifetime::Singleton) {
            throw new \InvalidArgumentException('Service instances must be singletons: ' . $id);
        }
        if (is_string($implementation) && $implementation === '') {
            throw new \InvalidArgumentException('Service implementation must not be empty: ' . $id);
        }
        if ($implementation !== null && !is_string($implementation) && !is_callable($implementation)) {
            throw new \InvalidArgumentException('Service implementation must be a class name or a factory: ' . $id);
        }
    }

    public static function singleton(string $id, string|callable|null $implementation = null): self
    {
        return new self($id, ServiceLifetime::Singleton, $implementation);
    }

    public static function scoped(string $id, string|callable|null $implementation = null): self
    {
        return new self($id, ServiceLifetime::Scoped, $implementation);
    }

    public static function transient(string $id, string|callable|null $implementation = null): self
    {
        return new self($id, ServiceLifetime::Transient, $implementation);
    }

    public static function instance(string $id, object $instance): self
    {
        return new self($id, ServiceLifetime::Singleton, null, $instance);
    }
}
