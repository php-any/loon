<?php

declare(strict_types=1);

namespace Loom;

/**
 * 检查当前请求能否升级，升级后把连接交给回调，并在回调结束时关闭连接。
 *
 * 构造参数：
 * - $auth（Auth）：accept 时调用 checkOrigin。
 * - $requestContext（RequestContext）：accept 时取当前请求和传输。
 *
 * 返回值：使用 new 构造时得到尚未升级连接的 WebSocket。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $websocket = new WebSocket($auth, $requestContext);
 */
final class WebSocket
{
    private \Loom\Auth $auth;
    private RequestContext $requestContext;
    private \Loom\WebSocket\HandlerDispatcher $dispatcher;

    public function __construct(\Loom\Auth $auth, \Loom\RequestContext $requestContext, \Loom\WebSocket\HandlerDispatcher $dispatcher)
    {
        $this->auth = $auth;
        $this->requestContext = $requestContext;
        $this->dispatcher = $dispatcher;
    }
    /**
     * 先 accept。升级成功后把当前解释器的时间限制设为 0，再把请求和连接交给 action。
     * action 返回或抛错后都会关闭连接；抛错时异常在关闭之后继续抛出。accept 失败时不会调用 action，也不会改时间限制。
     * 正常结束时返回正文为空的 204。升级检查用的是上下文里的当前请求，传给 action 的是本方法的 request 参数。
     *
     * @param callable $action 接收 Request 和已升级连接的回调。
     * @param Request $request 传给回调的请求。
     * @return Response 状态码 204、正文为空的响应。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $response = $websocket->dispatch($action, $request);
     */
    public function dispatch(callable $action, Request $request, ServiceResolver $services): Response
    {
        $connection = $this->accept();
        // 仅修改已升级请求的解释器执行预算，不影响其他 HTTP 请求。
        // 套接字限制、传输取消和应用认证仍然有效。
        set_time_limit(0);
        try {
            $this->dispatcher->dispatch($action, $request, $connection, $services);
        } finally {
            $connection->close();
        }
        return new Response('', 204);
    }
    /**
     * 从上下文取出当前请求。方法不是 GET、Upgrade 不是 websocket，或 Connection 里没有独立的 upgrade 标记时，抛出 HTTP 426。
     * 通过后调用认证的 checkOrigin，再从请求作用域的传输层 upgrade 并返回连接。
     *
     * 参数：无。
     *
     * @return \Net\Websocket\Conn 升级后的连接。源站检查失败时异常来自 Auth。
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
        $connection = $this->requestContext->transport()->upgrade();
        return $connection;
    }
}
