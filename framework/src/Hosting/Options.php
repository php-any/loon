<?php

declare(strict_types=1);

namespace Loom;

/**
 * 把配置数组绑定到公开属性上。对应 .NET 的 Options 绑定；容器的 options() 会把结果缓存为单例。
 *
 * 构造参数：
 * - $config（Config）：提供配置节的配置对象。bind 每次读取当前值，不另行缓存。
 *
 * 返回值：使用 new 构造时得到 Options 实例；根作用域把它注册为 singleton。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $payment = $services->get(Options::class)->bind(PaymentOptions::class, 'payment');
 */
final class Options
{
    /**
     * 保存配置来源。
     *
     * @param Config $config 项目配置实例。
     * @return void 无直接返回值。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $options = new Options($config);
     */
    public function __construct(private Config $config)
    {
    }

    /**
     * 用配置节填充一个新的选项对象。节必须是数组；多余的键忽略。
     * 没有默认值的公开类型化属性必须出现在配置里。null、false、0 和空字符串会原样写入。
     * 属性类型为具体类且配置值是数组时，按同样规则递归绑定。
     *
     * @param string $class 拥有无必填参数构造函数的具体类名。
     * @param string $section 点分配置节，例如 payment 或 payment.gateway。
     * @return object 填充后的新实例。调用方通过 options() 注册时，容器负责缓存。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $payment = $options->bind(PaymentOptions::class, 'payment');
     */
    public function bind(string $class, string $section): object
    {
        if ($class === '' || $section === '') {
            throw new \InvalidArgumentException('Options class and section must not be empty');
        }
        $missing = new \stdClass();
        $data = $this->config->get($section, $missing);
        if ($data === $missing) {
            throw new \LogicException('Missing configuration "' . $section . '" for ' . $class);
        }
        if (!is_array($data)) {
            throw new \LogicException('Configuration "' . $section . '" must be an array to bind ' . $class);
        }
        return $this->fill($class, $data, $section);
    }

    /**
     * 与 bind 相同，但配置节不存在时当作空数组，保留选项类属性上的默认值。
     * 仅适用于所有公开选项属性在类上已初始化的选项类型。
     *
     * @param string $class 拥有无必填参数构造函数的具体类名。
     * @param string $section 点分配置节。
     */
    public function bindOptional(string $class, string $section): object
    {
        if ($class === '' || $section === '') {
            throw new \InvalidArgumentException('Options class and section must not be empty');
        }
        $missing = new \stdClass();
        $data = $this->config->get($section, $missing);
        if ($data === $missing) {
            $data = [];
        }
        if (!is_array($data)) {
            throw new \LogicException('Configuration "' . $section . '" must be an array to bind ' . $class);
        }
        return $this->fill($class, $data, $section);
    }

    /**
     * 创建对象并写入公开属性。
     *
     * @param string $class 具体选项类名。
     * @param array $data 当前配置节。
     * @param string $path 用于异常定位的配置路径，不包含配置值。
     * @return object 填充后的实例。
     *
     * 内部调用示例（仅类内使用）：
     * @example $options = $this->fill($class, $data, $section);
     */
    private function fill(string $class, array $data, string $path): object
    {
        if (!class_exists($class)) {
            throw new \LogicException('Options class does not exist: ' . $class);
        }
        $reflection = new \ReflectionClass($class);
        if (!$reflection->isInstantiable()) {
            throw new \LogicException('Options class must be instantiable: ' . $class);
        }
        $constructor = $reflection->getConstructor();
        if ($constructor !== null) {
            foreach ($constructor->getParameters() as $parameter) {
                if (!$parameter->isOptional() && !$parameter->isDefaultValueAvailable()) {
                    throw new \LogicException('Options class requires a constructor without required parameters: ' . $class);
                }
            }
        }
        $instance = $reflection->newInstance();
        foreach ($reflection->getProperties() as $property) {
            if ($property->isStatic() || !$property->isPublic()) {
                continue;
            }
            $name = $property->getName();
            if (!array_key_exists($name, $data)) {
                if (!$property->isInitialized($instance)) {
                    throw new \LogicException('Missing option "' . $path . '.' . $name . '" for ' . $class);
                }
                continue;
            }
            $this->assign($property, $instance, $data[$name], $path);
        }
        return $instance;
    }

    /**
     * 检查类型后写入属性。异常只包含配置路径，不包含配置值。
     *
     * @param \ReflectionProperty $property 目标公开属性。
     * @param object $target 正在填充的选项对象。
     * @param mixed $value 配置中的原始值。
     * @param string $path 父级配置路径。
     * @return void 无返回值。
     *
     * 内部调用示例（仅类内使用）：
     * @example $this->assign($property, $instance, $value, $path);
     */
    private function assign(\ReflectionProperty $property, object $target, mixed $value, string $path): void
    {
        $name = $path . '.' . $property->getName();
        if ($property->hasType()) {
            $type = $property->getType();
            if ($type instanceof \ReflectionNamedType && !$type->isBuiltin() && is_array($value)) {
                $value = $this->fill($type->getName(), $value, $name);
            } elseif (!$this->matches($type, $value)) {
                throw new \LogicException('Option "' . $name . '" has the wrong type');
            }
        }
        try {
            $property->setValue($target, $value);
        } catch (\Throwable) {
            throw new \LogicException('Option "' . $name . '" has the wrong type');
        }
    }

    /**
     * 判断配置值能否赋给声明类型。整数可以赋给 float。不接受数字字符串。
     *
     * @param \ReflectionType $type 属性类型。
     * @param mixed $value 配置值。
     * @return bool 类型兼容时为 true。
     *
     * 内部调用示例（仅类内使用）：
     * @example $ok = $this->matches($property->getType(), $value);
     */
    private function matches(\ReflectionType $type, mixed $value): bool
    {
        if ($type instanceof \ReflectionUnionType) {
            foreach ($type->getTypes() as $part) {
                if ($this->matches($part, $value)) {
                    return true;
                }
            }
            return false;
        }
        if (!($type instanceof \ReflectionNamedType)) {
            return false;
        }
        if ($value === null) {
            return $type->allowsNull();
        }
        $name = $type->getName();
        if (!$type->isBuiltin()) {
            return is_object($value) && is_a($value, $name);
        }
        return match ($name) {
            'string' => is_string($value),
            'int' => is_int($value),
            'float' => is_float($value) || is_int($value),
            'bool' => is_bool($value),
            'array' => is_array($value),
            'mixed' => true,
            default => false,
        };
    }
}
