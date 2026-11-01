<?php

declare(strict_types=1);

namespace Loom;

/**
 * 在一个服务作用域内保存当前请求，允许临时替换后恢复；未绑定请求时拒绝读取。
 *
 * 构造参数：无，本类型未声明构造参数。
 *
 * 返回值：使用 new 构造时得到 RequestContext 实例；注册到容器时由容器管理实例的创建与复用。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $context = new RequestContext(); $context->bind($request);
 */
final class RequestContext
{
    private ?Request $request = null;
    /**
     * 绑定当前请求，传 null 时清除上下文。
     *
     * @param ?Request $request 当前 HTTP 请求；中间件修改属性时应传递请求副本。
     * @return void 无返回值。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $context->bind(null);
     */
    public function bind(?Request $request): void
    {
        $this->request = $request;
    }
    /**
     * 替换当前请求并返回旧请求，供嵌套分发在 finally 中恢复。
     *
     * @param ?Request $request 当前 HTTP 请求；中间件修改属性时应传递请求副本。
     * @return ?Request 此前绑定的请求；此前未绑定时为 null。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $previous = $context->replace($request);
     */
    public function replace(?Request $request): ?Request
    {
        $previous = $this->request;
        $this->request = $request;
        return $previous;
    }
    /**
     * 取得当前请求；作用域没有活动请求时抛出 LogicException。
     *
     * 参数：无。
     *
     * @return Request 当前绑定的 Request。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $request = $context->current();
     */
    public function current(): Request
    {
        if ($this->request === null) {
            throw new \LogicException('No active request');
        }
        return $this->request;
    }
}
