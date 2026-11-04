<?php

declare(strict_types=1);

namespace Loom\Console;

/**
 * 应用把自己的命令补进控制台的契约。内核在内置命令和模块命令都登记完后，若容器里有这个接口的实现，会调用一次 register。
 *
 * 构造参数：无，接口不声明构造函数。
 *
 * 返回值：不能直接实例化，由实现类完成登记。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $services->scoped(\Loom\Console\CommandRegistrar::class, \App\Console\AppCommandRegistrar::class);
 */
interface CommandRegistrar
{
    /**
     * 往已经装好框架和模块命令的控制台追加应用命令。实现里应只做登记，不要在这里启动服务或跑迁移。命令名重复时由 Console 抛出 LogicException。
     *
     * @param Console $console 内核已经登记内置命令和模块命令的控制台。
     * @return void 无返回值，新命令留在该控制台的登记表里。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $registrar->register($console);
     */
    public function register(Console $console): void;
}
