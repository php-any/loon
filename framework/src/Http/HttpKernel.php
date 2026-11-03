<?php

declare(strict_types=1);

namespace Loom;

/**
 * 把路由匹配、中间件、控制器解析及 WebSocket 分发组合为请求处理管道。
 *
 * 构造参数：
 * - $router（RouteMatcher）：路由器或路由匹配器，负责解析请求处理目标。
 * - $container（Container）：服务容器；允许 null 时由当前对象创建或选择默认容器。
 *
 * 返回值：使用 new 构造时得到 HttpKernel 实例；注册到容器时由容器管理实例的创建与复用。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $kernel = new HttpKernel($router, $services); $response = $kernel->handle($request);
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
     * 初始化 HttpKernel 实例及其依赖。把路由匹配、中间件、控制器解析及 WebSocket 分发组合为请求处理管道。
     *
     * @param RouteMatcher $router 路由器或路由匹配器，负责解析请求处理目标。
     * @param Container $container 服务容器；允许 null 时由当前对象创建或选择默认容器。
     * @return void 无直接返回值；通过 new 表达式取得初始化后的实例。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $kernel = new HttpKernel($router, $services); $response = $kernel->handle($request);
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
     * 追加全局中间件，注册时验证定义是否合法。
     *
     * @param mixed $middleware 需要加入处理链的中间件定义。
     * @return self 当前内核，供链式注册使用。
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
     * 在当前请求作用域内处理请求。模型连接来自该作用域容器中的 Database，不通过执行帧参数切换作用域。
     *
     * @param Request $request 当前 HTTP 请求；中间件修改属性时应传递请求副本。
     * @return Response 处理后的 HTTP 响应。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $response = $kernel->handle($request);
     */
    public function handle(Request $request): Response
    {
        return $this->handleScoped($request);
    }
    /**
     * 临时绑定请求上下文，执行全局管道并把 HttpError 转为问题响应，最后恢复旧请求。
     *
     * @param Request $request 当前 HTTP 请求；中间件修改属性时应传递请求副本。
     * @return Response 全局中间件和目标处理完成后的响应。
     *
     * 内部调用示例（仅类内使用）：
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
     * 匹配路由，生成 404/405 或执行路由中间件及控制器；WebSocket 路由进入升级流程。
     *
     * @param Request $request 当前 HTTP 请求；中间件修改属性时应传递请求副本。
     * @return Response 路由响应或带 Allow 头的 405 问题响应。
     *
     * 内部调用示例（仅类内使用）：
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
