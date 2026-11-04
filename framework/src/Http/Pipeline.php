<?php

declare(strict_types=1);

namespace Loom;

/**
 * 按注册顺序把中间件包成调用链。先注册的先收到请求，链返回时则最后看到响应。
 *
 * 构造参数：
 * - $container（Container）：按类名取出中间件实例的服务容器。
 *
 * 返回值：使用 new 构造时得到 Pipeline 实例。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $pipeline = new Pipeline($services);
 */
final class Pipeline
{
    private Container $container;
    /**
     * 保存用来按类名解析中间件的服务容器。
     *
     * @param Container $container 按类名取出中间件实例的服务容器。
     * @return void 无直接返回值。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $pipeline = new Pipeline($services);
     */
    public function __construct(Container $container)
    {
        $this->container = $container;
    }
    /**
     * 逐项检查中间件定义。非空类名、Middleware 实例和可调用对象可以通过，其它值抛出 LogicException。
     *
     * @param array $middleware 待检查的中间件定义列表。
     * @return void 无返回值。任一元素不合法时抛出 LogicException。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example Pipeline::validate([\Loom\Http\Middleware\ParseJsonInput::class]);
     */
    public static function validate(array $middleware): void
    {
        foreach ($middleware as $entry) {
            if ((!is_string($entry) || $entry === '') && !($entry instanceof Middleware) && !is_callable($entry)) {
                throw new \LogicException('Expected middleware class, Middleware instance or callable');
            }
        }
    }
    /**
     * 从列表末尾向前包成调用链，再把请求交给最外层。字符串类名经容器解析。条目既不是 Middleware 也不可调用时抛出 LogicException。
     *
     * @param Request $request 交给链首的 HTTP 请求。
     * @param array $middleware 按先注册先执行排列的中间件，元素为类名、Middleware 或可调用对象。
     * @param callable $destination 链末端回调，接收 Request 并返回 Response。
     * @return Response 中间件或末端回调返回的响应。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $response = $pipeline->run($request, [\Loom\Http\Middleware\ParseJsonInput::class], fn (Request $r) => Response::json(['ok' => true]));
     */
    public function run(Request $request, array $middleware, callable $destination): Response
    {
        $next = $destination;
        for ($index = count($middleware) - 1; $index >= 0; $index--) {
            $entry = $middleware[$index];
            $next = function (Request $request) use ($entry, $next): Response {
                if (is_string($entry)) {
                    $entry = $this->container->get($entry);
                }
                if ($entry instanceof Middleware) {
                    return $entry->handle($request, $next);
                }
                if (!is_callable($entry)) {
                    throw new \LogicException('Middleware must implement Middleware or be callable');
                }
                return $entry($request, $next);
            };
        }
        return $next($request);
    }
}
