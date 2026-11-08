<?php

declare(strict_types=1);

namespace Loom;

/**
 * 解析服务的统一入口，对应 ASP.NET Core 的 IServiceProvider。根 ServiceProvider 和 ServiceScope 都实现它。
 *
 * 构造参数或工厂参数声明为 ServiceResolver 时，拿到的是发起这次解析的那一个：在作用域里是该作用域，在根上是根。
 */
interface ServiceResolver
{
    /**
     * 取出服务。没有登记、生命周期不允许在这里解析或构造失败时抛出 LogicException。
     * 没有登记的可实例化具体类按 Scoped 自动装配。
     */
    public function get(string $id): object;

    /**
     * 取出已登记的服务；没有登记时返回 null，不做自动装配。
     */
    public function tryGet(string $id): ?object;

    /**
     * 按登记顺序取出同一标识的全部实现。没有登记时返回空数组。
     *
     * @return list<object>
     */
    public function getAll(string $id): array;

    /**
     * 标识是否已登记。没有登记、只能自动装配的具体类返回 false。
     */
    public function has(string $id): bool;
}
