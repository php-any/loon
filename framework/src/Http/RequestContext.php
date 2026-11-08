<?php

declare(strict_types=1);

namespace Loom;

/**
 * 在当前服务作用域里保存一个请求，HTTP 请求还带着原生传输。可以临时换成另一个请求再恢复。没有活动请求时拒绝读取。
 *
 * 构造参数：无，本类型未声明构造参数。
 *
 * 返回值：使用 new 构造时得到 RequestContext 实例。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $context = new RequestContext();
 */
final class RequestContext
{
    private ?Request $request = null;
    /**
     * 把当前请求设为给出的实例。传入 null 时清除，之后 current 会失败。
     *
     * @param ?Request $request 要绑定的请求。null 表示清除。
     * @return void 无返回值。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $context->bind($request);
     */
    public function bind(?Request $request): void
    {
        $this->request = $request;
    }
    /**
     * 换成新请求并返回替换前的请求，供嵌套处理在 finally 中恢复。传入 null 会清空当前请求。
     *
     * @param ?Request $request 替换后的请求。null 表示暂时清空。
     * @return ?Request 替换前绑定的请求。此前没有请求时为 null。
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
     * 返回当前绑定的请求。尚未绑定或已经清除时抛出 LogicException。
     *
     * 参数：无。
     *
     * @return Request 当前绑定的请求。
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

    private ?HttpTransport $transport = null;

    /**
     * HttpServer 在请求开始时记下这次的原生传输，WebSocket 升级要用。作用域随请求释放，不需要解除。
     *
     * @param HttpTransport $transport 本次请求的传输对象。
     * @return void 无返回值。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $context->attach($transport);
     */
    public function attach(HttpTransport $transport): void
    {
        $this->transport = $transport;
    }

    /**
     * 返回这次 HTTP 请求的传输对象。不在 HTTP 请求里时抛出 LogicException。
     *
     * 参数：无。
     *
     * @return HttpTransport 当前传输对象。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $connection = $context->transport()->upgrade();
     */
    public function transport(): HttpTransport
    {
        if ($this->transport === null) {
            throw new \LogicException('No HTTP transport');
        }
        return $this->transport;
    }
}
