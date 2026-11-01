<?php

declare(strict_types=1);

namespace Loom;

/**
 * 验证路由处理器定义，并在真正分发时从容器解析控制器，避免路由匹配阶段构造业务对象。
 *
 * 构造参数：
 * - $container（Container）：服务容器；允许 null 时由当前对象创建或选择默认容器。
 *
 * 返回值：使用 new 构造时得到 ActionResolver 实例；注册到容器时由容器管理实例的创建与复用。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $resolver = new ActionResolver($services);
 */
final class ActionResolver
{
    private Container $container;
    /**
     * 初始化 ActionResolver 实例及其依赖。验证路由处理器定义，并在真正分发时从容器解析控制器，避免路由匹配阶段构造业务对象。
     *
     * @param Container $container 服务容器；允许 null 时由当前对象创建或选择默认容器。
     * @return void 无直接返回值；通过 new 表达式取得初始化后的实例。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $resolver = new ActionResolver($services);
     */
    public function __construct(Container $container)
    {
        $this->container = $container;
    }
    /**
     * 检查处理器是否为类名、可调用对象或 [控制器, 方法名] 结构；不实例化控制器。
     *
     * @param mixed $action 路由处理目标，可为容器中的类名、可调用对象或控制器方法。
     * @return void 无返回值；结构无效时抛出 LogicException。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example ActionResolver::validate([\App\Http\Controllers\HomeController::class, '__invoke']);
     */
    public static function validate(mixed $action): void
    {
        if (is_array($action)) {
            if (count($action) !== 2 || !array_key_exists(0, $action) || !array_key_exists(1, $action)
                || (!is_string($action[0]) && !is_object($action[0]))
                || !is_string($action[1]) || $action[1] === '') {
                throw new \LogicException('Expected [controller, method]');
            }
            return;
        }
        if (!is_string($action) && !is_callable($action)) {
            throw new \LogicException('Invalid route handler');
        }
    }
    /**
     * 把控制器类名或方法定义解析为公开可调用对象，类实例由容器提供。
     *
     * @param mixed $action 路由处理目标，可为容器中的类名、可调用对象或控制器方法。
     * @return callable 可接受 Request 的处理器；不可调用时抛出 LogicException。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $handler = $resolver->resolve($controllerClass);
     */
    public function resolve(mixed $action): callable
    {
        if (is_array($action)) {
            $controller = $action[0];
            if (is_string($controller)) {
                $controller = $this->container->get($controller);
            }
            $action = [$controller, $action[1]];
        } elseif (is_string($action)) {
            $action = $this->container->get($action);
        }
        if (!is_callable($action)) {
            throw new \LogicException('Route must resolve to a callable public controller method');
        }
        return $action;
    }
}
