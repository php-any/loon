<?php

declare(strict_types=1);

namespace Loom\Attributes;

/**
 * 标在模块类上，保存要扫描的命名空间目录，以及要先导入的模块类名。循环导入由发现器在 add 时拒绝。
 *
 * 构造参数：
 * - $scan（array）：命名空间到目录的映射。
 * - $imports（array）：依赖模块类名。默认空数组。
 *
 * 返回值：使用 new 构造时得到 Module。构造时不读取目录。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $module = new Module(['App' => 'app']);
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class Module
{
    public array $scan;
    public array $imports;
    /**
     * 把扫描映射和依赖模块列表写入公开属性。不读取那些目录，也不加载 imports 里的类。
     *
     * @param array $scan 命名空间到目录的映射。目录稍后由发现器按项目根解析。
     * @param array $imports 要先发现的模块类名。默认空数组。
     * @return void 无直接返回值。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $module = new Module(['App' => 'app']);
     */
    public function __construct(array $scan, array $imports = [])
    {
        $this->scan = $scan;
        $this->imports = $imports;
    }
}
