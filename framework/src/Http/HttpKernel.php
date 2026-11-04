<?php

declare(strict_types=1);

namespace Loom;

/**
 * 把路由匹配、全局中间件、控制器解析和 WebSocket 分发串成一次请求处理。
 *
 * 构造参数：
 * - $router（RouteMatcher）：按方法和路径匹配路由。
 * - $container（Container）：提供 WebSocket、RequestContext，以及控制器解析所用的服务容器。
 *
 * 返回值：使用 new 构造时得到 HttpKernel 实例。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $kernel = new HttpKernel($router, $services);
 */
final class HttpKernel
{
    private \Loom\WebSocket $webSocket;

    private RequestContext $requestContext;
    private RouteMatcher $router;
    private Pipeline $pipeline;
    private ActionResolver $actions;
    private Container $services;
    private array $middleware = [];
    /**
     * 保存路由器和容器，并立刻取出 WebSocket 与 RequestContext，同时建好 Pipeline 和 ActionResolver。全局中间件初始为空。
     *
     * @param RouteMatcher $router 按方法和路径匹配路由的对象。
     * @param Container $container 提供 WebSocket、RequestContext 和后续控制器解析的服务容器。
     * @return void 无直接返回值。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $kernel = new HttpKernel($router, $services);
     */
    public function __construct(RouteMatcher $router, Container $container)
    {
        $this->services = $container;
        $this->webSocket = $container->get(\Loom\WebSocket::class);
        $this->requestContext = $container->get(\Loom\RequestContext::class);

        $this->router = $router;
        $this->pipeline = new Pipeline($container);
        $this->actions = new ActionResolver($container);
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
     * 把请求交给 handleScoped，并返回那里得到的响应。
     *
     * @param Request $request 本次 HTTP 请求。
     * @return Response handleScoped 返回的响应。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $response = $kernel->handle($request);
     */
    public function handle(Request $request): Response
    {
        return $this->handleScoped($request);
    }
    /**
     * 临时绑定请求并跑完全局中间件，目标是 dispatch。HttpError 转成问题响应。finally 里恢复进入前的请求。
     *
     * @param Request $request 本次要绑定并处理的请求。
     * @return Response 全局管道返回的响应。捕获到的 HttpError 变成 problem 响应。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $response = $this->handleScoped($request);
     */
    private function handleScoped(Request $request): Response
    {
        $previous = $this->requestContext->replace($request);
        try {
            return $this->pipeline->run($request, $this->middleware, function (Request $request): Response {
                return $this->dispatch($request);
            });
        } catch (HttpError $error) {
            return Response::problem($error->status, $error->getMessage(), $error->details);
        } finally {
            $this->requestContext->bind($previous);
        }
    }
    /**
     * 匹配方法和路径。404 与 405 变成问题响应，405 带上 Allow 头。命中后把路由参数写入请求副本，再执行路由中间件和控制器；WebSocket 路由交给 WebSocket::dispatch。本方法捕获的 HttpError 也转成问题响应。
     *
     * @param Request $request 用来匹配并传给控制器的请求。
     * @return Response 控制器、WebSocket 分发，或 404、405 问题响应。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $response = $this->dispatch($request);
     */
    private function dispatch(Request $request): Response
    {
        // 在全局管道内部转换路由错误，确保响应中间件仍会执行。
        try {
            $match = $this->router->match($request->method(), $request->path());
            if ($match->status === 404) {
                return Response::problem(404, 'Not Found');
            }
            if ($match->status === 405) {
                return Response::problem(405, 'Method Not Allowed')->withHeader('Allow', implode(', ', $match->allowed));
            }
            return $this->pipeline->run($request->withParameters($match->parameters), $match->middleware, function (Request $request) use ($match): Response {
                $action = $this->actions->resolve($match->action);
                if ($match->websocket) {
                    return $this->webSocket->dispatch($action, $request);
                }
                return $action($request);
            });
        } catch (HttpError $error) {
            return Response::problem($error->status, $error->getMessage(), $error->details);
        }
    }
}
