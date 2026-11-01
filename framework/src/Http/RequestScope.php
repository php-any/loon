<?php

declare(strict_types=1);

namespace Loom;

// 每个实例管理一个 HTTP 传输对象及其清理过程。
/**
 * 管理单次 HTTP 请求的传输对象、数据库清理和请求上下文，不允许在活动期间重复打开。
 *
 * 构造参数：
 * - $database（\Loom\Database）：当前作用域的数据库服务，负责连接、查询及事务。
 * - $requestContext（RequestContext）：当前作用域的请求上下文，负责绑定与恢复请求。
 *
 * 返回值：使用 new 构造时得到 RequestScope 实例；注册到容器时由容器管理实例的创建与复用。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $requestScope = $services->get(RequestScope::class);
 */
final class RequestScope
{
    private \Loom\Database $database;
    private RequestContext $requestContext;

    /**
     * 初始化 RequestScope 实例及其依赖。管理单次 HTTP 请求的传输对象、数据库清理和请求上下文，不允许在活动期间重复打开。
     *
     * @param \Loom\Database $database 当前作用域的数据库服务，负责连接、查询及事务。
     * @param RequestContext $requestContext 当前作用域的请求上下文，负责绑定与恢复请求。
     * @return void 无直接返回值；通过 new 表达式取得初始化后的实例。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $requestScope = $services->get(RequestScope::class);
     */
    public function __construct(\Loom\Database $database, RequestContext $requestContext)
    {
        $this->database = $database;
        $this->requestContext = $requestContext;
    }

    private ?HttpTransport $transport = null;
    /**
     * 记录当前传输对象；重复打开活动请求作用域时抛出 LogicException。
     *
     * @param HttpTransport $transport 负责原生请求转换和响应写出的 HTTP 传输适配器。
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
     * 取得当前 HTTP 传输对象；未打开作用域时抛出 LogicException。
     *
     * 参数：无。
     *
     * @return HttpTransport 当前 HttpTransport 实例。
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
     * 关闭数据库连接并清除请求上下文和传输引用，即使数据库清理失败也执行后两项。
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
