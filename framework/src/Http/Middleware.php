<?php

declare(strict_types=1);

namespace Loom;

/**
 * 中间件契约。实现可以在调用下游之前或之后处理请求，也可以直接返回响应。
 *
 * 构造参数：无，本类型未声明构造参数。
 *
 * 返回值：接口不能直接实例化。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $response = $middleware->handle($request, fn (Request $r) => Response::json(['ok' => true]));
 */
interface Middleware
{
    /**
     * 处理当前请求。需要继续分发时调用 next，并把请求传给它；不调用 next 即提前结束。
     *
     * @param Request $request 本次要处理的 HTTP 请求。
     * @param callable $next 下游回调，接收 Request 并返回 Response。
     * @return Response 本中间件生成的响应，或 next 返回的响应。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $response = $middleware->handle($request, $next);
     */
    public function handle(Request $request, callable $next): Response;
}
