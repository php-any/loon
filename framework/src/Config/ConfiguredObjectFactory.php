<?php

declare(strict_types=1);

namespace Loom;

/**
 * 容器默认对象工厂，将构造参数解析和属性配置注入委托给 ConfigInjector。
 *
 * 构造参数：无，本类型未声明构造参数。
 *
 * 返回值：使用 new 构造时得到 ConfiguredObjectFactory 实例；注册到容器时由容器管理实例的创建与复用。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $services = new Container(new ConfiguredObjectFactory());
 */
final class ConfiguredObjectFactory implements ObjectFactory
{
    /**
     * 解析构造参数中的服务或配置依赖并实例化类。
     *
     * @param string $class 需要解析或创建的完整类名。
     * @param Container $services 提供依赖解析和实例注册的服务容器。
     * @return object 新构造的对象；属性注入由 initialize 继续处理。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $object = $factory->create($class, $services);
     */
    public function create(string $class, Container $services): object
    {
        return ConfigInjector::create($class, $services);
    }
    /**
     * 为对象及继承层级上的 InjectConfig 属性注入配置。
     *
     * @param object $instance 需要初始化或注册的对象实例。
     * @param Container $services 提供依赖解析和实例注册的服务容器。
     * @return void 无返回值，直接初始化传入对象。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $factory->initialize($object, $services);
     */
    public function initialize(object $instance, Container $services): void
    {
        ConfigInjector::inject($instance, $services);
    }
}
