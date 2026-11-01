<?php

declare(strict_types=1);

namespace Loom\Attributes;

/**
 * 声明可被模块发现的容器服务，空标识使用类名，非空标识注册为指向类的别名。
 *
 * 构造参数：
 * - $id（string）：服务标识；空字符串表示使用被标记类的完整类名。默认值为 ''。
 *
 * 返回值：使用 new 构造时得到 Service 实例；注册到容器时由容器管理实例的创建与复用。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example #[\Loom\Attributes\Service('clock')] final class Clock {}
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class Service
{
    public string $id;
    /**
     * 初始化 Service 实例及其依赖。声明可被模块发现的容器服务，空标识使用类名，非空标识注册为指向类的别名。
     *
     * @param string $id 服务标识；空字符串表示使用被标记类的完整类名。默认值为 ''。
     * @return void 无直接返回值；通过 new 表达式取得初始化后的实例。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example #[\Loom\Attributes\Service('clock')] final class Clock {}
     */
    public function __construct(string $id = '')
    {
        $this->id = $id;
    }
}
