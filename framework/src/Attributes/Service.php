<?php

declare(strict_types=1);

namespace Loom\Attributes;

/**
 * 标在具体类上，让模块发现把它登记成容器服务。标识为空时，发现器改用类名。
 *
 * 构造参数：
 * - $id（string）：服务标识。默认空字符串。
 * - $lifetime（string）：必须是 singleton、scoped 或 transient。默认 scoped。
 *
 * 返回值：使用 new 构造时得到 Service。生命周期不在三者之中时构造失败。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $service = new Service('clock', 'singleton');
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class Service
{
    public string $id;
    public string $lifetime;
    /**
     * lifetime 必须是 singleton、scoped 或 transient，否则抛出 LogicException。通过后保存标识和生命周期。
     * 不检查标识是否与其他服务重复。
     *
     * @param string $id 服务标识。空字符串留给发现器替换成类名。默认空字符串。
     * @param string $lifetime 生命周期，只能是 singleton、scoped 或 transient。默认 scoped。
     * @return void 无直接返回值。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $service = new Service('clock', 'singleton');
     */
    public function __construct(string $id = '', string $lifetime = 'scoped')
    {
        if (!in_array($lifetime, ['singleton', 'scoped', 'transient'], true)) {
            throw new \LogicException('Invalid service lifetime: ' . $lifetime);
        }
        $this->id = $id;
        $this->lifetime = $lifetime;
    }
}
