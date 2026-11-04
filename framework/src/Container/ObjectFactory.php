<?php

declare(strict_types=1);

namespace Loom;

/**
 * 容器创建对象时的两步契约：create 负责按类名构造，initialize 负责写完属性。
 *
 * 构造参数：无，本类型未声明构造参数。
 *
 * 返回值：接口不能直接实例化。构造参数怎样解析、属性怎样写入，由实现类决定。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $object = $factory->create($class, $services);
 */
interface ObjectFactory
{
    /**
     * 按类名造出一个新对象。构造参数从给出的容器解析，具体规则由实现类决定。
     * 本方法结束时对象只是被构造出来，属性初始化不在这一步完成。
     *
     * @param string $class 要创建的完整类名。
     * @param Container $services 解析这个类的构造依赖时使用的容器。
     * @return object 新构造的对象。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $object = $factory->create($class, $services);
     */
    public function create(string $class, Container $services): object;
    /**
     * 对已经构造好的对象做属性初始化。对象从哪来由调用方决定，本方法只改传入的实例。
     *
     * @param object $instance 已经构造好、等待写入属性的对象。
     * @param Container $services 解析属性上的依赖时使用的容器。
     * @return void 无返回值。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $factory->initialize($object, $services);
     */
    public function initialize(object $instance, Container $services): void;
}
