<?php

declare(strict_types=1);

namespace Loom;

/**
 * HTTP 请求管道：全局中间件、路由匹配、路由中间件、处理器，对应 ASP.NET Core 的 RequestDelegate 管道加端点路由。
 * 路由表和全局中间件属于应用，在开始监听前装好，各次请求共用；控制器、中间件和请求上下文从这次请求的作用域解析。
 *
 * 闭包处理器按参数声明绑定：Request 取当前请求，其它类类型从作用域解析。控制器方法收到 Request。
 *
 * 构造参数：
 * - $router（RouteMatcher）：按方法和路径匹配路由。
 *
 * 返回值：使用 new 构造时得到 HttpKernel 实例。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $response = $kernel->handle($request, $scope);
 */
final class HttpKernel
{
    private RouteMatcher $router;
    private array $middleware = [];

    /**
     * 保存路由表。构造时不解析请求、控制器或中间件。全局中间件初始为空。
     *
     * @param RouteMatcher $router 按方法和路径匹配路由的对象。
     * @return void 无直接返回值。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $kernel = new HttpKernel($router);
     */
    public function __construct(RouteMatcher $router)
    {
        $this->router = $router;
    }

    /**
     * 把一个全局中间件加到链尾。定义不是非空类名、Middleware 或可调用对象时抛出 LogicException。
     *
     * @param mixed $middleware 类名、Middleware 实例或可调用对象。
     * @return self 当前内核，便于连续登记。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $kernel->middleware(\Loom\Http\Middleware\RequestLog::class);
     */
    public function middleware(mixed $middleware): self
    {
        Pipeline::validate([$middleware]);
        $this->middleware[] = $middleware;
        return $this;
    }

    /**
     * 在给出的作用域里临时绑定请求并跑完全局中间件，最后到路由分发。HttpError 转成问题响应。finally 里恢复进入前的请求。
     *
     * @param Request $request 本次 HTTP 请求。
     * @param ServiceResolver $services 这次请求的作用域，解析 RequestContext、中间件和控制器。
     * @return Response 全局管道返回的响应。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $response = $kernel->handle($request, $scope);
     */
    public function handle(Request $request, ServiceResolver $services): Response
    {
        $context = $services->get(RequestContext::class);
        $pipeline = new Pipeline($services);
        $previous = $context->replace($request);
        try {
            return $pipeline->run($request, $this->middleware, function (Request $request) use ($services, $pipeline): Response {
                return $this->dispatch($request, $services, $pipeline);
            });
        } catch (HttpError $error) {
            return Response::problem($error->status, $error->getMessage(), $error->details);
        } finally {
            $context->bind($previous);
        }
    }

    /**
     * 匹配方法和路径。404 与 405 变成问题响应，405 带上 Allow 头。命中后把路由参数写入请求副本，再执行路由中间件和处理器；
     * WebSocket 路由交给作用域里的 WebSocket::dispatch。本方法捕获的 HttpError 也转成问题响应，确保全局中间件仍能看到响应。
     *
     * @param Request $request 用来匹配并传给处理器的请求。
     * @param ServiceResolver $services 解析控制器和 WebSocket 的作用域。
     * @param Pipeline $pipeline 执行路由中间件的管道，使用同一个作用域。
     * @return Response 处理器、WebSocket 分发，或 404、405 问题响应。
     *
     * 内部调用示例（仅类内使用）：
     * @example $response = $this->dispatch($request, $services, $pipeline);
     */
    private function dispatch(Request $request, ServiceResolver $services, Pipeline $pipeline): Response
    {
        try {
            $match = $this->router->match($request->method(), $request->path());
            if ($match->status === 404) {
                return Response::problem(404, 'Not Found');
            }
            if ($match->status === 405) {
                return Response::problem(405, 'Method Not Allowed')->withHeader('Allow', implode(', ', $match->allowed));
            }
            $actions = new ActionResolver($services);
            return $pipeline->run($request->withParameters($match->parameters), $match->middleware, function (Request $request) use ($match, $services, $actions): Response {
                $action = $actions->resolve($match->action);
                if ($match->websocket) {
                    return $services->get(\Loom\WebSocket::class)->dispatch($action, $request, $services);
                }
                $response = Invoker::call($action, $services, [$request]);
                if (!($response instanceof Response)) {
                    throw new \LogicException('Route handler must return a Response');
                }
                return $response;
            });
        } catch (HttpError $error) {
            return Response::problem($error->status, $error->getMessage(), $error->details);
        }
    }
}
