<?php

declare(strict_types=1);

namespace Loom;

/**
 * 检查路由处理器的形状，并在分发时才从容器取出控制器。
 *
 * 构造参数：
 * - $container（Container）：按类名取出控制器的服务容器。
 *
 * 返回值：使用 new 构造时得到 ActionResolver 实例。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $resolver = new ActionResolver($services);
 */
final class ActionResolver
{
    private Container $container;
    /**
     * 保存服务容器。构造时不创建控制器。
     *
     * @param Container $container 按类名取出控制器的服务容器。
     * @return void 无直接返回值。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $resolver = new ActionResolver($services);
     */
    public function __construct(Container $container)
    {
        $this->container = $container;
    }
    /**
     * 检查处理器是 [控制器, 方法名]、类名字符串或可调用对象。数组长度不是 2、方法名为空，或控制器既不是字符串也不是对象时抛出 LogicException。这里不实例化控制器。
     *
     * @param mixed $action 待检查的处理器定义。
     * @return void 无返回值。结构无效时抛出 LogicException。
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
     * 把类名或 [类名, 方法] 变成可调用对象。字符串类名通过容器取出，数组中的对象控制器保持原实例。结果不可调用时抛出 LogicException。
     *
     * @param mixed $action 类名、可调用对象，或 [控制器类名或实例, 方法名]。
     * @return callable 可接受 Request 的公开可调用对象。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $handler = $resolver->resolve([\App\Http\Controllers\HomeController::class, '__invoke']);
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
