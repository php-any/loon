<?php

declare(strict_types=1);

namespace Loom;

/**
 * ObjectFactory 的默认实现。create 和 initialize 都转给 ConfigInjector，本类自己不保存状态。
 *
 * 构造参数：无，本类型未声明构造参数。
 *
 * 返回值：使用 new 构造时得到可传给容器的 ConfiguredObjectFactory。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $factory = new ConfiguredObjectFactory();
 */
final class ConfiguredObjectFactory implements ObjectFactory
{
    /**
     * 调用 ConfigInjector::create，按构造参数上的注解和类型创建对象。
     *
     * @param string $class 交给 ConfigInjector 的完整类名。
     * @param Container $services 构造时用来解析依赖的容器。
     * @return object ConfigInjector::create 返回的对象。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $object = $factory->create($class, $services);
     */
    public function create(string $class, Container $services): object
    {
        return ConfigInjector::create($class, $services);
    }
    /**
     * 调用 ConfigInjector::inject，把对象继承链上的 InjectConfig 属性写上配置值。
     *
     * @param object $instance 要写入配置属性的对象。
     * @param Container $services 注入时用来取出 Config 的容器。
     * @return void 无返回值。注入失败时异常来自 ConfigInjector。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $factory->initialize($object, $services);
     */
    public function initialize(object $instance, Container $services): void
    {
        ConfigInjector::inject($instance, $services);
    }
}
