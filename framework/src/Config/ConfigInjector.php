<?php

declare(strict_types=1);

namespace Loom;

/**
 * 按构造参数上的 Inject、InjectConfig 或类类型从容器取值并 new 出对象，再按继承链给带 InjectConfig 的实例属性赋值。
 *
 * 构造参数：无，本类型未声明构造参数。
 *
 * 返回值：本类型的 create 和 inject 都是静态方法，不需要先构造注入器。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $instance = ConfigInjector::create($class, $services);
 */
final class ConfigInjector
{
    /**
     * 没有构造函数时直接 new。否则按参数顺序取值。
     * 同一参数上有多个 Inject，或 Inject 与 InjectConfig 同时存在，抛出 LogicException。Inject 的标识为空，或标在可变参数上，同样失败。
     * 有 Inject 时从容器 get 该标识。有 InjectConfig 时调用它的 resolve；可变参数上的 InjectConfig 会失败。
     * 两者都没有时，非可变的类类型参数按类型名向容器 get；类型是未登记的接口时，依次改用默认值或 null。再否则使用参数默认值。仍然无法决定且该参数不是可变参数时抛出 LogicException。
     * 返回值只完成构造，属性上的 InjectConfig 还没有写入。
     *
     * @param string $id 要 new 的完整类名。
     * @param ServiceResolver $services 发起解析的容器，用来解析 Inject、类类型参数以及 Config 服务。
     * @return object 已按构造参数创建的对象。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $instance = ConfigInjector::create($class, $services);
     */
    public static function create(string $id, ServiceResolver $services): object
    {
        $class = new \ReflectionClass($id);
        $constructor = $class->getConstructor();
        if ($constructor === null) {
            return new $id();
        }
        $arguments = [];
        foreach ($constructor->getParameters() as $parameter) {
            $target = $constructor->getDeclaringClass()->getName() . '::__construct($' . $parameter->getName() . ')';
            $attributes = $parameter->getAttributes(\Loom\InjectConfig::class);
            $dependencies = $parameter->getAttributes(\Loom\Attributes\Inject::class);
            if (count($dependencies) > 1 || ($dependencies !== [] && $attributes !== [])) {
                throw new \LogicException('Conflicting injection annotations on ' . $target);
            }
            if ($dependencies !== []) {
                $dependency = $dependencies[0]->newInstance();
                if ($dependency->id === '' || $parameter->isVariadic()) {
                    throw new \LogicException('Invalid service injection: ' . $target);
                }
                $arguments[] = $services->get($dependency->id);
                continue;
            }
            if (count($attributes) > 1) {
                throw new \LogicException('Duplicate InjectConfig on ' . $target);
            }
            if ($attributes !== []) {
                if ($parameter->isVariadic()) {
                    throw new \LogicException('InjectConfig does not support variadic parameters: ' . $target);
                }
                $attribute = $attributes[0]->newInstance();
                $arguments[] = $attribute->resolve($target, $services->get(\Loom\Config::class));
            } elseif ($parameter->getType() instanceof \ReflectionNamedType && !$parameter->getType()->isBuiltin() && !$parameter->isVariadic()) {
                $type = $parameter->getType()->getName();
                if ($services->has($type) || class_exists($type) || (!$parameter->isDefaultValueAvailable() && !$parameter->getType()->allowsNull())) {
                    $arguments[] = $services->get($type);
                } elseif ($parameter->isDefaultValueAvailable()) {
                    $arguments[] = $parameter->getDefaultValue();
                } else {
                    $arguments[] = null;
                }
            } elseif ($parameter->isDefaultValueAvailable()) {
                $arguments[] = $parameter->getDefaultValue();
            } elseif (!$parameter->isVariadic()) {
                throw new \LogicException('Cannot resolve ' . $target . '; add InjectConfig or register a service factory');
            }
        }
        return $class->newInstanceArgs($arguments);
    }

    /**
     * 从对象的类走到父类，只处理当前这一层声明的属性。同名的非私有属性只处理子类那一次。
     * 没有 InjectConfig 的属性跳过。重复注解或标在静态属性上时抛出 LogicException。
     * 赋值失败时改抛 LogicException，消息含配置键和属性位置，不含配置值。
     *
     * @param object $instance 要写入配置属性的对象。
     * @param ServiceResolver $services 用来取出 Config 的容器。
     * @return void 无返回值。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example ConfigInjector::inject($instance, $services);
     */
    public static function inject(object $instance, ServiceResolver $services): void
    {
        $class = new \ReflectionClass($instance);
        $seen = [];
        do {
            foreach ($class->getProperties() as $property) {
                // 显式遍历声明，以包含每个父类的私有属性。
                if ($property->getDeclaringClass()->getName() !== $class->getName()) {
                    continue;
                }
                $name = $property->getName();
                if (!$property->isPrivate() && isset($seen[$name])) {
                    continue;
                }
                if (!$property->isPrivate()) {
                    $seen[$name] = true;
                }
                $attributes = $property->getAttributes(\Loom\InjectConfig::class);
                if ($attributes === []) {
                    continue;
                }
                $target = $class->getName() . '::$' . $name;
                if (count($attributes) !== 1) {
                    throw new \LogicException('Duplicate InjectConfig on ' . $target);
                }
                if ($property->isStatic()) {
                    throw new \LogicException('InjectConfig requires an instance property: ' . $target);
                }
                $attribute = $attributes[0]->newInstance();
                $value = $attribute->resolve($target, $services->get(\Loom\Config::class));
                try {
                    $property->setValue($instance, $value);
                } catch (\Throwable $error) {
                    // 标识配置项与注入目标，但不暴露配置值。
                    throw new \LogicException('Cannot inject configuration "' . $attribute->key . '" into ' . $target);
                }
            }
            $class = $class->getParentClass();
        } while ($class !== false);
    }
}
