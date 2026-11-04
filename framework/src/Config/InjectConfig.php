<?php

declare(strict_types=1);

namespace Loom;

/**
 * InjectConfig 用来区分「调用方没有写默认值」和「默认值就是 null」的哨兵。没有方法，只供类型判断。
 *
 * 构造参数：无，本类型未声明构造参数。
 *
 * 返回值：使用 new 构造时得到一个空哨兵。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $missing = new MissingConfigValue();
 */
final class MissingConfigValue
{
}

/**
 * 标在构造参数或实例属性上的配置注入。resolve 按 key 读配置，没有这个键时使用构造时留下的默认值。
 *
 * 构造参数：
 * - $key（string）：点分配置键，原样保存。
 * - $default（mixed）：键不存在时 resolve 返回的值。缺省是 MissingConfigValue，表示没有默认值。
 *
 * 返回值：使用 new 构造时得到保存了键和默认值的 InjectConfig。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $inject = new InjectConfig('http.address', '127.0.0.1:8080');
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_PARAMETER)]
final class InjectConfig
{
    public string $key;
    public mixed $default;

    /**
     * 把配置键和默认值写入公开属性。空键也照样保存，留到 resolve 再拒绝。
     *
     * @param string $key 之后 resolve 使用的点分配置键。
     * @param mixed $default 键不存在时的回退值。默认是新的 MissingConfigValue，表示调用方没有提供默认值。
     * @return void 无直接返回值。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $inject = new InjectConfig('http.address', '127.0.0.1:8080');
     */
    public function __construct(string $key, mixed $default = new MissingConfigValue())
    {
        $this->key = $key;
        $this->default = $default;
    }

    /**
     * 键是空字符串时抛出 InvalidArgumentException，消息里带有 target。
     * 用一个私有哨兵调用 config 的 get：配置里能取到值就返回该值，包括 null。
     * 缺键时，默认值的类型是 MissingConfigValue 则抛出 LogicException，否则返回默认值，包括显式 null。异常文本含键和 target，不含配置值。
     *
     * @param string $target 写进异常的注入位置。
     * @param Config $config 按本对象的 key 读取的配置。
     * @return mixed 配置中的值，或构造时保存的默认值。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $value = (new InjectConfig('http.address'))->resolve('Server::$address', $config);
     */
    public function resolve(string $target, Config $config): mixed
    {
        if ($this->key === '') {
            throw new \InvalidArgumentException('Configuration key must not be empty: ' . $target);
        }
        $missing = new \stdClass();
        $value = $config->get($this->key, $missing);
        if ($value !== $missing) {
            return $value;
        }
        if (!($this->default instanceof MissingConfigValue)) {
            return $this->default;
        }
        throw new \LogicException('Missing configuration "' . $this->key . '" for ' . $target);
    }
}
