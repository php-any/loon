<?php

declare(strict_types=1);

namespace Loom\Http\Middleware;

use Loom\Middleware;
use Loom\Request;
use Loom\Response;

/**
 * 把 JSON 正文解码成 Input，放进请求副本的 input 属性后再交给下游。
 *
 * 构造参数：无，本类型未声明构造参数。
 *
 * 返回值：使用 new 构造时得到 ParseJsonInput 实例。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $middleware = new ParseJsonInput();
 */
final class ParseJsonInput implements Middleware
{
    /**
     * 调用请求的 json 方法，把得到的 Input 写入副本的 input 属性，再交给 next。媒体类型或正文不合法时 json 抛出 HttpError，不会调用 next。
     *
     * @param Request $request 要解析正文的请求。传给下游的是带 input 属性的副本。
     * @param callable $next 下游回调，接收带 input 属性的 Request 并返回 Response。
     * @return Response next 返回的响应。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $response = (new ParseJsonInput())->handle($request, $next);
     */
    public function handle(Request $request, callable $next): Response
    {
        return $next($request->withAttribute('input', $request->json()));
    }
}
