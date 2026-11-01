<?php

declare(strict_types=1);

namespace Loom;

/**
 * 抽象对象构造与属性初始化，供容器替换默认反射和配置注入策略。
 *
 * 构造参数：无，本类型未声明构造参数。
 *
 * 返回值：接口不能直接实例化；由实现类提供本契约，操作结果见各方法的返回值。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $services = new Container($customObjectFactory);
 */
interface ObjectFactory
{
    /**
     * 根据类名和当前容器创建对象，实现者决定构造参数如何解析。
     *
     * @param string $class 需要解析或创建的完整类名。
     * @param Container $services 提供依赖解析和实例注册的服务容器。
     * @return object 新创建的业务对象。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $object = $factory->create($class, $services);
     */
    public function create(string $class, Container $services): object;
    /**
     * 在构造或工厂返回对象后完成属性等初始化。
     *
     * @param object $instance 需要初始化或注册的对象实例。
     * @param Container $services 提供依赖解析和实例注册的服务容器。
     * @return void 无返回值，直接修改传入对象。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $factory->initialize($object, $services);
     */
    public function initialize(object $instance, Container $services): void;
}
