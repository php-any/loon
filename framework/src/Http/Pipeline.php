<?php

declare(strict_types=1);

namespace Loom;

/**
 * 按注册顺序包装中间件，让最早注册的中间件最先收到请求、最后收到响应。
 *
 * 构造参数：
 * - $container（Container）：服务容器；允许 null 时由当前对象创建或选择默认容器。
 *
 * 返回值：使用 new 构造时得到 Pipeline 实例；注册到容器时由容器管理实例的创建与复用。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $pipeline = new Pipeline($services);
 */
final class Pipeline
{
    private Container $container;
    /**
     * 初始化 Pipeline 实例及其依赖。按注册顺序包装中间件，让最早注册的中间件最先收到请求、最后收到响应。
     *
     * @param Container $container 服务容器；允许 null 时由当前对象创建或选择默认容器。
     * @return void 无直接返回值；通过 new 表达式取得初始化后的实例。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $pipeline = new Pipeline($services);
     */
    public function __construct(Container $container)
    {
        $this->container = $container;
    }
    /**
     * 检查中间件列表中的每项是否为非空类名、中间件实例或可调用对象。
     *
     * @param array $middleware 按执行顺序排列的中间件定义列表。
     * @return void 无返回值；无效定义抛出 LogicException。
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
     * 从后向前组合处理链，并将请求交给首个中间件；字符串类名按需由容器解析。
     *
     * @param Request $request 当前 HTTP 请求；中间件修改属性时应传递请求副本。
     * @param array $middleware 按执行顺序排列的中间件定义列表。
     * @param callable $destination 最终目标回调，签名为 callable(Request): Response。
     * @return Response 中间件或最终目标返回的 Response。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $response = $pipeline->run($request, [], fn (Request $r) => Response::json(['ok' => true]));
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
