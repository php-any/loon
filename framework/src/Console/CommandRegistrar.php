<?php

declare(strict_types=1);

namespace Loom\Console;

/**
 * 声明应用命令注册契约；在容器中绑定此接口，由内核在构建控制台时调用一次。
 *
 * 构造参数：无，本类型未声明构造参数。
 *
 * 返回值：接口不能直接实例化；由实现类提供本契约，操作结果见各方法的返回值。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $services->alias(CommandRegistrar::class, AppCommandRegistrar::class);
 */
interface CommandRegistrar
{
    /**
     * 将应用命令加入给定控制台；实现应只注册定义，避免提前启动服务。
     *
     * @param Console $console 内核已安装框架和模块命令的控制台，重复命令名会被拒绝。
     * @return void 无返回值，修改控制台的命令注册表。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $registrar->register($console);
     */
    public function register(Console $console): void;
}
