<?php

declare(strict_types=1);

namespace Loom\Attributes;

/**
 * 标记控制器类并声明公共路由前缀和中间件，方法路由在此基础上组合。
 *
 * 构造参数：
 * - $prefix（string）：控制器所有路由的公共路径前缀，例如 /api。默认值为 ''。
 * - $middleware（array）：控制器级中间件列表，先于方法级中间件执行。默认值为 []。
 *
 * 返回值：使用 new 构造时得到 Controller 实例；注册到容器时由容器管理实例的创建与复用。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example #[\Loom\Attributes\Controller('/api')] final class ExampleController {}
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class Controller
{
    public string $prefix;
    public array $middleware;
    /**
     * 初始化 Controller 实例及其依赖。标记控制器类并声明公共路由前缀和中间件，方法路由在此基础上组合。
     *
     * @param string $prefix 控制器所有路由的公共路径前缀，例如 /api。默认值为 ''。
     * @param array $middleware 控制器级中间件列表，先于方法级中间件执行。默认值为 []。
     * @return void 无直接返回值；通过 new 表达式取得初始化后的实例。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example #[\Loom\Attributes\Controller('/api')] final class ExampleController {}
     */
    public function __construct(string $prefix = '', array $middleware = [])
    {
        $this->prefix = $prefix;
        $this->middleware = $middleware;
    }
}
