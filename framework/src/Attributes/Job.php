<?php

declare(strict_types=1);

namespace Loom\Attributes;

/**
 * 将任务种类映射到类中的公开实例处理方法，供队列按 kind 分发。
 *
 * 构造参数：
 * - $kind（string）：队列使用的非空任务种类，同一模块集合内不能重复。
 * - $method（string）：接收 Request 并返回 Response 的公开实例方法名，默认 handle。默认值为 'handle'。
 *
 * 返回值：使用 new 构造时得到 Job 实例；注册到容器时由容器管理实例的创建与复用。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example #[\Loom\Attributes\Job('report.build', 'handle')] final class ReportJob { public function handle(\Loom\Request $request): \Loom\Response { return \Loom\Response::json(['ok' => true]); } }
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class Job
{
    public string $kind;
    public string $method;
    /**
     * 初始化 Job 实例及其依赖。将任务种类映射到类中的公开实例处理方法，供队列按 kind 分发。
     *
     * @param string $kind 队列使用的非空任务种类，同一模块集合内不能重复。
     * @param string $method 接收 Request 并返回 Response 的公开实例方法名，默认 handle。默认值为 'handle'。
     * @return void 无直接返回值；通过 new 表达式取得初始化后的实例。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example #[\Loom\Attributes\Job('report.build', 'handle')] final class ReportJob { public function handle(\Loom\Request $request): \Loom\Response { return \Loom\Response::json(['ok' => true]); } }
     */
    public function __construct(string $kind, string $method = 'handle')
    {
        $this->kind = $kind;
        $this->method = $method;
    }
}
