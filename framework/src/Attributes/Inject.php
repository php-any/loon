<?php

declare(strict_types=1);

namespace Loom\Attributes;

/**
 * 标在构造参数上，让 ConfigInjector 按标识取服务。同一参数不能再标 InjectConfig，这项冲突由注入器拒绝。
 *
 * 构造参数：
 * - $id（string）：要保存的服务标识。构造时不检查它是否为空，也不查询容器。
 *
 * 返回值：使用 new 构造时得到 Inject。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $inject = new Inject('clock');
 */
#[\Attribute(\Attribute::TARGET_PARAMETER)]
final class Inject
{
    public string $id;
    /**
     * 把服务标识写入公开属性。不向容器查询这个标识能否解析。
     *
     * @param string $id 构造该参数时要向容器 get 的服务标识。
     * @return void 无直接返回值。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $inject = new Inject('clock');
     */
    public function __construct(string $id)
    {
        $this->id = $id;
    }
}
