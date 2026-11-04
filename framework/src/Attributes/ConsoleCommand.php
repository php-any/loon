<?php

declare(strict_types=1);

namespace Loom\Attributes;

/**
 * 标在控制台命令类上的空标记。发现器看到它之后，才要求该类是可实例化的 Command 子类。本类不检查被标记的类。
 *
 * 构造参数：无，本类型未声明构造参数。
 *
 * 返回值：使用 new 构造时得到空的 ConsoleCommand。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $mark = new ConsoleCommand();
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class ConsoleCommand
{
}
