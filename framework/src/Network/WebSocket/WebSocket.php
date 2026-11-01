<?php

declare(strict_types=1);

namespace Loom;

/**
 * 验证升级请求和同源条件，取得当前请求传输后升级，确保业务处理结束时关闭连接。
 *
 * 构造参数：
 * - $auth（\Loom\Auth）：当前作用域的认证服务，负责会话与身份校验。
 * - $requestContext（\Loom\RequestContext）：当前作用域的请求上下文，负责绑定与恢复请求。
 * - $requestScope（RequestScope）：负责建立单次请求生命周期的作用域服务。
 *
 * 返回值：使用 new 构造时得到 WebSocket 实例；注册到容器时由容器管理实例的创建与复用。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $websocket = $services->get(WebSocket::class);
 */
final class WebSocket
{
    private \Loom\Auth $auth;

    /**
     * 初始化 WebSocket 实例及其依赖。验证升级请求和同源条件，取得当前请求传输后升级，确保业务处理结束时关闭连接。
     *
     * @param \Loom\Auth $auth 当前作用域的认证服务，负责会话与身份校验。
     * @param \Loom\RequestContext $requestContext 当前作用域的请求上下文，负责绑定与恢复请求。
     * @param RequestScope $requestScope 负责建立单次请求生命周期的作用域服务。
     * @return void 无直接返回值；通过 new 表达式取得初始化后的实例。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $websocket = $services->get(WebSocket::class);
     */
    public function __construct(\Loom\Auth $auth, \Loom\RequestContext $requestContext, RequestScope $requestScope)
    {
        $this->auth = $auth;
        $this->requestContext = $requestContext;
        $this->requestScope = $requestScope;
    }

    private RequestContext $requestContext;
    private RequestScope $requestScope;
    /**
     * 升级连接后取消当前解释器时间预算，调用业务处理器，并在 finally 中关闭连接。
     *
     * @param callable $action 接收 Request 和 Net\Websocket\Conn 的业务回调；结束后框架关闭连接。
     * @param Request $request 当前 HTTP 请求；中间件修改属性时应传递请求副本。
     * @return Response 空正文的 204 Response，仅用于完成框架分发流程。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $response = $websocket->dispatch($handler, $request);
     */
    public function dispatch(callable $action, Request $request): Response
    {
        $connection = $this->accept();
        // 仅修改已升级请求的解释器执行预算，不影响其他 HTTP 请求。
        // 套接字限制、传输取消和应用认证仍然有效。
        set_time_limit(0);
        try {
            $action($request, $connection);
        } finally {
            $connection->close();
        }
        return new Response('', 204);
    }
    /**
     * 检查 GET、Upgrade 和 Connection 头并校验 Origin，随后执行原生升级。
     *
     * 参数：无。
     *
     * @return \Net\Websocket\Conn 配置了网络限制的原生 WebSocket 连接；非升级请求抛出 HTTP 426。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $connection = $websocket->accept();
     */
    public function accept(): \Net\Websocket\Conn
    {
        $request = $this->requestContext->current();
        if ($request->method() !== 'GET' || strtolower($request->header('upgrade')) !== 'websocket'
            || !preg_match('/(^|,)\s*upgrade\s*(,|$)/i', $request->header('connection'))) {
            throw new HttpError(426, 'WebSocket upgrade required');
        }
        $this->auth->checkOrigin();
        $connection = $this->requestScope->transport()->upgrade();
        return $connection;
    }
}
