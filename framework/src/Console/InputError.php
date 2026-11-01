<?php

declare(strict_types=1);

namespace Loom\Console;

/**
 * 区分命令行输入错误与程序内部错误，Console 将该异常转换为退出码 2。无自定义构造参数，沿用 RuntimeException。
 *
 * 构造参数：本声明未定义构造参数；继承类型沿用父类的构造约定。
 *
 * 返回值：使用 new 构造时得到 InputError 实例；注册到容器时由容器管理实例的创建与复用。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example throw new \Loom\Console\InputError('缺少必填参数');
 */
final class InputError extends \RuntimeException
{
}
