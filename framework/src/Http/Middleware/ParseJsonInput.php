<?php

declare(strict_types=1);

namespace Loom\Http\Middleware;

use Loom\Middleware;
use Loom\Request;
use Loom\Response;

/**
 * 验证并解码 JSON 请求体，把 Input 放入请求的 input 属性后继续处理。
 *
 * 构造参数：无，本类型未声明构造参数。
 *
 * 返回值：使用 new 构造时得到 ParseJsonInput 实例；注册到容器时由容器管理实例的创建与复用。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $app->middleware(\Loom\Http\Middleware\ParseJsonInput::class);
 */
final class ParseJsonInput implements Middleware
{
    /**
     * 解析 JSON 并通过请求副本传递 input 属性；类型或正文无效时直接抛出 HttpError。
     *
     * @param Request $request 当前 HTTP 请求；中间件修改属性时应传递请求副本。
     * @param callable $next 下游处理器，接收 Request 并返回 Response。
     * @return Response 后续中间件或控制器的响应。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $response = $middleware->handle($request, $next);
     */
    public function handle(Request $request, callable $next): Response
    {
        return $next($request->withAttribute('input', $request->json()));
    }
}
