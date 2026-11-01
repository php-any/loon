<?php

declare(strict_types=1);

namespace Loom;

/**
 * 标记 InjectConfig 未显式提供默认值的内部哨兵；没有输入参数，实例只用于类型判断。
 *
 * 构造参数：无，本类型未声明构造参数。
 *
 * 返回值：使用 new 构造时得到 MissingConfigValue 实例；注册到容器时由容器管理实例的创建与复用。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $missing = new MissingConfigValue(); // 仅作为未提供默认值的内部标记
 */
final class MissingConfigValue
{
}

/**
 * 配置注入属性，可用于构造参数或实例属性；区分未提供默认值和显式 null。
 *
 * 构造参数：
 * - $key（string）：点分配置键，例如 http.address，不能为空。
 * - $default（mixed）：键不存在时返回的值；MissingConfigValue 表示未声明默认值。默认值为 new MissingConfigValue()。
 *
 * 返回值：使用 new 构造时得到 InjectConfig 实例；注册到容器时由容器管理实例的创建与复用。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example #[InjectConfig('http.address', '127.0.0.1:8080')] public string $address;
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_PARAMETER)]
final class InjectConfig
{
    public string $key;
    public mixed $default;

    /**
     * 初始化 InjectConfig 实例及其依赖。配置注入属性，可用于构造参数或实例属性；区分未提供默认值和显式 null。
     *
     * @param string $key 点分配置键，例如 http.address，不能为空。
     * @param mixed $default 键不存在时返回的值；MissingConfigValue 表示未声明默认值。默认值为 new MissingConfigValue()。
     * @return void 无直接返回值；通过 new 表达式取得初始化后的实例。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example #[InjectConfig('http.address', '127.0.0.1:8080')] public string $address;
     */
    public function __construct(string $key, mixed $default = new MissingConfigValue())
    {
        $this->key = $key;
        $this->default = $default;
    }

    /**
     * 读取点分配置键并应用显式默认值；键为空或缺失且无默认值时抛出异常。
     *
     * @param string $target 注入目标的可读描述，用于异常定位，不应包含敏感配置值。
     * @param Config $config 项目配置实例，提供配置读取及项目路径解析。
     * @return mixed 实际配置值或调用方声明的默认值，保持原类型。
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
