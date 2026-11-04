<?php

declare(strict_types=1);

namespace Loom\Console;

/**
 * 表示命令行用法或签名解析失败。Console::run 捕获后把消息写到错误输出并返回退出码 2，不把它收成内部错误的退出码 1。本类没有自己的构造函数，消息、代码和前一个异常沿用 RuntimeException。
 *
 * 构造参数：无自身构造参数，使用父类 RuntimeException 的消息、代码和前一个异常。
 *
 * 返回值：可被 Console::run 识别为输入错误的异常。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example throw new \Loom\Console\InputError('缺少必填参数');
 */
final class InputError extends \RuntimeException
{
}
