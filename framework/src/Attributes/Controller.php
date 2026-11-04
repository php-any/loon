<?php

declare(strict_types=1);

namespace Loom\Attributes;

/**
 * 标在控制器类上，保存路由前缀和类级中间件。发现器把前缀拼到每条路由路径前面。
 *
 * 构造参数：
 * - $prefix（string）：路径前缀。默认空字符串。
 * - $middleware（array）：类级中间件。默认空数组。
 *
 * 返回值：使用 new 构造时得到 Controller。构造时不检查前缀格式。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $controller = new Controller('/api');
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class Controller
{
    public string $prefix;
    public array $middleware;
    /**
     * 把前缀和类级中间件写入公开属性。不解析中间件，也不要求前缀以斜杠开头。
     *
     * @param string $prefix 发现路由时拼在路径前面的前缀。默认空字符串。
     * @param array $middleware 与每条路由中间件合并的类级中间件。默认空数组。
     * @return void 无直接返回值。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $controller = new Controller('/api');
     */
    public function __construct(string $prefix = '', array $middleware = [])
    {
        $this->prefix = $prefix;
        $this->middleware = $middleware;
    }
}
