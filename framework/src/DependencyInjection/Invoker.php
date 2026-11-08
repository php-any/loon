<?php

declare(strict_types=1);

namespace Loom;

/**
 * 调用闭包处理器，并按参数声明填入实参，对应 ASP.NET Core minimal API 的参数绑定。
 *
 * 宿主给出这次调用的现成实参（例如 Request，或命令的 Input 和 Output）。每个参数：
 * - 类类型：先找类型相符的现成实参，再从这次的作用域解析服务；解析不到时用默认值或 null。
 * - 无类型或标量类型：按顺序取下一个还没用掉的现成实参，没有时用默认值。
 *
 * 不是闭包的可调用对象按现成实参原样调用。
 *
 * @example Invoker::call(static fn (Request $request, Queue $queue): Response => ..., $scope, [$request]);
 */
final class Invoker
{
    /**
     * @param callable $handler 要调用的处理器。
     * @param ServiceResolver $services 解析服务参数的作用域。
     * @param list<mixed> $arguments 宿主提供的现成实参。
     * @return mixed 处理器的返回值。
     */
    public static function call(callable $handler, ServiceResolver $services, array $arguments = []): mixed
    {
        if (!($handler instanceof \Closure)) {
            return $handler(...$arguments);
        }
        $available = array_values($arguments);
        $values = [];
        foreach ((new \ReflectionFunction($handler))->getParameters() as $parameter) {
            $type = $parameter->getType();
            if ($type instanceof \ReflectionNamedType && !$type->isBuiltin()) {
                $values[] = self::service($parameter, $type, $services, $available);
                continue;
            }
            if ($available !== []) {
                $values[] = array_shift($available);
            } elseif ($parameter->isDefaultValueAvailable()) {
                $values[] = $parameter->getDefaultValue();
            } else {
                throw new \LogicException('Cannot bind handler parameter $' . $parameter->getName());
            }
        }
        return $handler(...$values);
    }

    private static function service(\ReflectionParameter $parameter, \ReflectionNamedType $type, ServiceResolver $services, array &$available): mixed
    {
        $class = $type->getName();
        foreach ($available as $index => $argument) {
            if ($argument instanceof $class) {
                unset($available[$index]);
                $available = array_values($available);
                return $argument;
            }
        }
        if ($services->has($class) || class_exists($class)) {
            return $services->get($class);
        }
        if ($parameter->isDefaultValueAvailable()) {
            return $parameter->getDefaultValue();
        }
        if ($type->allowsNull()) {
            return null;
        }
        throw new \LogicException('Cannot bind handler parameter $' . $parameter->getName() . ' of type ' . $class);
    }
}
