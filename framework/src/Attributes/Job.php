<?php

declare(strict_types=1);

namespace Loom\Attributes;

/**
 * 标在任务类上，保存 kind 和要调用的方法名。空 kind 和重复 kind 由发现器拒绝，本类不检查。
 *
 * 构造参数：
 * - $kind（string）：任务种类。
 * - $method（string）：处理方法名。默认 handle。
 *
 * 返回值：使用 new 构造时得到 Job。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $job = new Job('report.build', 'handle');
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class Job
{
    public string $kind;
    public string $method;
    /**
     * 把任务种类和方法名写入公开属性。不检查 kind 是否为空，也不检查方法是否存在。
     *
     * @param string $kind 用来查找处理器的任务种类。
     * @param string $method 处理请求的公开实例方法名。默认 handle。
     * @return void 无直接返回值。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $job = new Job('report.build');
     */
    public function __construct(string $kind, string $method = 'handle')
    {
        $this->kind = $kind;
        $this->method = $method;
    }
}
