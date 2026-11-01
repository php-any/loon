<?php

declare(strict_types=1);

namespace Loom\Attributes;

/**
 * 声明模块扫描命名空间和依赖模块列表；模块依赖先发现，循环导入会报错。
 *
 * 构造参数：
 * - $scan（array）：命名空间到扫描目录的映射，目录相对项目根目录或使用绝对路径。
 * - $imports（array）：依赖模块完整类名列表，依赖先于当前模块发现。默认值为 []。
 *
 * 返回值：使用 new 构造时得到 Module 实例；注册到容器时由容器管理实例的创建与复用。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example #[\Loom\Attributes\Module(['App' => 'app'])] final class AppModule {}
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class Module
{
    public array $scan;
    public array $imports;
    /**
     * 初始化 Module 实例及其依赖。声明模块扫描命名空间和依赖模块列表；模块依赖先发现，循环导入会报错。
     *
     * @param array $scan 命名空间到扫描目录的映射，目录相对项目根目录或使用绝对路径。
     * @param array $imports 依赖模块完整类名列表，依赖先于当前模块发现。默认值为 []。
     * @return void 无直接返回值；通过 new 表达式取得初始化后的实例。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example #[\Loom\Attributes\Module(['App' => 'app'])] final class AppModule {}
     */
    public function __construct(array $scan, array $imports = [])
    {
        $this->scan = $scan;
        $this->imports = $imports;
    }
}
