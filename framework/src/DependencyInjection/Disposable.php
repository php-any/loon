<?php

declare(strict_types=1);

namespace Loom;

/**
 * 需要在生命周期结束时清理的服务，对应 .NET 的 IDisposable。
 *
 * 容器创建的 Scoped 和 Transient 实例在作用域释放时清理，Singleton 在根容器释放时清理，都按创建的相反顺序。
 * 通过 addInstance 交给容器的现成对象由调用方自己清理。
 */
interface Disposable
{
    public function dispose(): void;
}
