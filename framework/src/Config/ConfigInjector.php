<?php

declare(strict_types=1);

namespace Loom;

/**
 * 反射构造函数和继承属性，解析 Inject、InjectConfig 及类类型依赖，并报告不可解析或冲突的声明。
 *
 * 构造参数：无，本类型未声明构造参数。
 *
 * 返回值：使用 new 构造时得到 ConfigInjector 实例；注册到容器时由容器管理实例的创建与复用。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $instance = ConfigInjector::create($serviceClass, $services); ConfigInjector::inject($instance, $services);
 */
final class ConfigInjector
{
    /**
     * 按构造参数注解、类类型及默认值解析依赖；不支持对可变参数使用注入注解。
     *
     * @param string $id 需要反射构造的可实例化完整类名。
     * @param Container $services 提供依赖解析和实例注册的服务容器。
     * @return object 新构造对象；属性注入需单独调用 inject。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $instance = ConfigInjector::create($serviceClass, $services);
     */
    public static function create(string $id, Container $services): object
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
                $arguments[] = $services->get($parameter->getType()->getName());
            } elseif ($parameter->isDefaultValueAvailable()) {
                $arguments[] = $parameter->getDefaultValue();
            } elseif (!$parameter->isVariadic()) {
                throw new \LogicException('Cannot resolve ' . $target . '; add InjectConfig or register a service factory');
            }
        }
        return $class->newInstanceArgs($arguments);
    }

    /**
     * 遍历当前类和父类的实例属性并注入配置，拒绝重复注解或静态配置属性。
     *
     * @param object $instance 需要注入配置属性的已构造对象。
     * @param Container $services 提供依赖解析和实例注册的服务容器。
     * @return void 无返回值；无法赋值时抛出不包含敏感配置值的 LogicException。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example ConfigInjector::inject($instance, $services);
     */
    public static function inject(object $instance, Container $services): void
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
