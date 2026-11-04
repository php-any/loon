<?php

declare(strict_types=1);

namespace Loom;

// 每个实例管理一个 HTTP 传输对象及其清理过程。
/**
 * 记下单次请求的传输对象，并在关闭时解除请求绑定。活动期间不能再次打开。
 *
 * 构造参数：
 * - $database（\Loom\Database）：关闭时调用其 close。不关闭进程级连接池。
 * - $requestContext（RequestContext）：关闭时把当前请求绑回 null。
 *
 * 返回值：使用 new 构造时得到 RequestScope 实例。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $requestScope = new RequestScope($database, $requestContext);
 */
final class RequestScope
{
    private \Loom\Database $database;
    private RequestContext $requestContext;

    /**
     * 保存数据库和请求上下文。传输对象留到 open 再记录，构造时不打开请求。
     *
     * @param \Loom\Database $database 关闭作用域时调用其 close。这里不关闭进程级连接池。
     * @param RequestContext $requestContext 关闭时把当前请求绑回 null。
     * @return void 无直接返回值。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $requestScope = new RequestScope($database, $requestContext);
     */
    public function __construct(\Loom\Database $database, RequestContext $requestContext)
    {
        $this->database = $database;
        $this->requestContext = $requestContext;
    }

    private ?HttpTransport $transport = null;
    /**
     * 记下这次请求的传输对象。已经有活动传输时抛出 LogicException，不会替换它。
     *
     * @param HttpTransport $transport 本请求使用的传输对象。
     * @return void 无返回值。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $requestScope->open($transport);
     */
    public function open(HttpTransport $transport): void
    {
        if ($this->transport !== null) {
            throw new \LogicException('An HTTP request scope is already active');
        }
        $this->transport = $transport;
    }
    /**
     * 返回 open 记下的传输对象。尚未打开或已经关闭时抛出 LogicException。
     *
     * 参数：无。
     *
     * @return HttpTransport 当前传输对象。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $transport = $requestScope->transport();
     */
    public function transport(): HttpTransport
    {
        if ($this->transport === null) {
            throw new \LogicException('No HTTP transport');
        }
        return $this->transport;
    }
    /**
     * 调用 database->close()，再解除请求绑定并丢掉传输引用。close 若抛出，清空仍会执行，异常继续向外抛。不关闭进程级连接池。
     *
     * 参数：无。
     *
     * @return void 无返回值。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $requestScope->close();
     */
    public function close(): void
    {
        try {
            $this->database->close();
        } finally {
            $this->requestContext->bind(null);
            $this->transport = null;
        }
    }
}
