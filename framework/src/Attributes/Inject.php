<?php

declare(strict_types=1);

namespace Loom\Attributes;

/**
 * 为构造参数指定服务标识，替代按类型自动解析；不能与 InjectConfig 同时用于同一参数。
 *
 * 构造参数：
 * - $id（string）：非空服务标识，必须能由当前容器解析。
 *
 * 返回值：使用 new 构造时得到 Inject 实例；注册到容器时由容器管理实例的创建与复用。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example public function __construct(#[\Loom\Attributes\Inject('clock')] \DateTimeImmutable $clock) {}
 */
#[\Attribute(\Attribute::TARGET_PARAMETER)]
final class Inject
{
    public string $id;
    /**
     * 初始化 Inject 实例及其依赖。为构造参数指定服务标识，替代按类型自动解析；不能与 InjectConfig 同时用于同一参数。
     *
     * @param string $id 非空服务标识，必须能由当前容器解析。
     * @return void 无直接返回值；通过 new 表达式取得初始化后的实例。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example public function __construct(#[\Loom\Attributes\Inject('clock')] \DateTimeImmutable $clock) {}
     */
    public function __construct(string $id)
    {
        $this->id = $id;
    }
}
