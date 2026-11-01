<?php

declare(strict_types=1);

namespace Loom\Attributes;

/**
 * 标记可被模块发现的控制台命令类；类必须是可实例化的 Console\Command 子类，此注解无参数。
 *
 * 构造参数：无，本类型未声明构造参数。
 *
 * 返回值：使用 new 构造时得到 ConsoleCommand 实例；注册到容器时由容器管理实例的创建与复用。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example #[\Loom\Attributes\ConsoleCommand] final class ReportCommand extends \Loom\Console\Command { protected string $signature = 'report'; public function handle(): int { return 0; } }
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class ConsoleCommand
{
}
