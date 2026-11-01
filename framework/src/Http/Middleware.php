<?php

declare(strict_types=1);

namespace Loom;

/**
 * 定义可包裹后续请求处理步骤的中间件契约，可提前返回响应或调用 next。
 *
 * 构造参数：无，本类型未声明构造参数。
 *
 * 返回值：接口不能直接实例化；由实现类提供本契约，操作结果见各方法的返回值。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $response = $middleware->handle($request, fn (Request $r) => Response::json(['ok' => true]));
 */
interface Middleware
{
    /**
     * 在下一处理步骤前后执行业务逻辑；需要继续分发时调用 next($request)。
     *
     * @param Request $request 当前 HTTP 请求；中间件修改属性时应传递请求副本。
     * @param callable $next 下游处理器，接收 Request 并返回 Response。
     * @return Response 本中间件生成或后续处理返回的 Response。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $response = $middleware->handle($request, $next);
     */
    public function handle(Request $request, callable $next): Response;
}
