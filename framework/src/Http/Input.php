<?php

declare(strict_types=1);

namespace Loom;

/**
 * 对 JSON 对象字段执行严格的存在性、类型、长度和范围校验，失败时抛出带字段原因的 HTTP 422。
 *
 * 构造参数：
 * - $values（array）：待验证的字段名到值的关联数组，通常来自 JSON 对象。
 *
 * 返回值：使用 new 构造时得到 Input 实例；注册到容器时由容器管理实例的创建与复用。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $input = new Input(['name' => '小明', 'age' => 18]);
 */
final class Input
{
    private array $values;
    /**
     * 初始化 Input 实例及其依赖。对 JSON 对象字段执行严格的存在性、类型、长度和范围校验，失败时抛出带字段原因的 HTTP 422。
     *
     * @param array $values 待验证的字段名到值的关联数组，通常来自 JSON 对象。
     * @return void 无直接返回值；通过 new 表达式取得初始化后的实例。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $input = new Input(['name' => '小明', 'age' => 18]);
     */
    public function __construct(array $values)
    {
        $this->values = $values;
    }
    /**
     * 判断字段是否存在；值为 null 的字段也算存在。
     *
     * @param string $key 待验证字段的名称。
     * @return bool 存在返回 true，否则 false。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $hasName = $input->has('name');
     */
    public function has(string $key): bool
    {
        return array_key_exists($key, $this->values);
    }
    /**
     * 读取必填列表，拒绝关联数组及超出数量上限的列表。
     *
     * @param string $key 待验证字段的名称。
     * @param int $max 列表元素数量上限或整数允许的最大值，具体含义取决于方法。默认值为 100。
     * @return array 保持原始元素类型的顺序数组。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $ids = $input->items('ids', 100);
     */
    public function items(string $key, int $max = 100): array
    {
        $value = $this->required($key);
        if (!is_array($value) || count($value) > $max || array_values($value) !== $value) {
            $this->invalid($key, 'list');
        }
        return $value;
    }
    /**
     * 拒绝白名单以外的字段，不会静默丢弃多余字段。
     *
     * @param array $fields 允许出现的字段名列表。
     * @return self 校验通过后的当前 Input 实例。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $input->only(['name', 'age']);
     */
    public function only(array $fields): self
    {
        foreach ($this->values as $key => $value) {
            if (!in_array($key, $fields, true)) {
                $this->invalid((string) $key, 'unknown');
            }
        }
        return $this;
    }
    /**
     * 读取必填且非 null 的字段，分别报告 missing 和 null 原因。
     *
     * @param string $key 待验证字段的名称。
     * @return mixed 字段的原始值。
     *
     * 内部调用示例（仅类内使用）：
     * @example $value = $this->required('name');
     */
    private function required(string $key): mixed
    {
        if (!array_key_exists($key, $this->values)) {
            $this->invalid($key, 'missing');
        }
        if ($this->values[$key] === null) {
            $this->invalid($key, 'null');
        }
        return $this->values[$key];
    }
    /**
     * 统一抛出 HTTP 422，携带 field 和 reason 供客户端定位错误。
     *
     * @param string $key 待验证字段的名称。
     * @param string $reason 错误原因，如 missing、null、type、length 或 range。
     * @return void 无正常返回；始终抛出 HttpError。
     *
     * 内部调用示例（仅类内使用）：
     * @example $this->invalid('age', 'range');
     */
    private function invalid(string $key, string $reason): void
    {
        throw new HttpError(422, 'Invalid input', ['field' => $key, 'reason' => $reason]);
    }
    /**
     * 读取严格字符串，并按字节数校验长度；长度边界配置错误时抛出 LogicException。
     *
     * @param string $key 待验证字段的名称。
     * @param int $minBytes 字符串允许的最少字节数，不能为负数。默认值为 0。
     * @param int $maxBytes 字符串允许的最多字节数，不能小于 minBytes。默认值为 4096。
     * @return string 校验后的原字符串，不自动 trim 或转换类型。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $name = $input->string('name', 1, 80);
     */
    public function string(string $key, int $minBytes = 0, int $maxBytes = 4096): string
    {
        if ($minBytes < 0 || $maxBytes < $minBytes) {
            throw new \LogicException('Invalid string limits');
        }
        $value = $this->required($key);
        if (!is_string($value)) {
            $this->invalid($key, 'type');
        }
        if (strlen($value) < $minBytes || strlen($value) > $maxBytes) {
            $this->invalid($key, 'length');
        }
        return $value;
    }
    /**
     * 读取严格整数并检查闭区间范围，不接受数字字符串。
     *
     * @param string $key 待验证字段的名称。
     * @param int $min 允许的最小整数，包含边界。默认值为 PHP_INT_MIN。
     * @param int $max 列表元素数量上限或整数允许的最大值，具体含义取决于方法。默认值为 PHP_INT_MAX。
     * @return int 校验后的整数。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $age = $input->integer('age', 0, 150);
     */
    public function integer(string $key, int $min = PHP_INT_MIN, int $max = PHP_INT_MAX): int
    {
        if ($max < $min) {
            throw new \LogicException('Invalid integer limits');
        }
        $value = $this->required($key);
        if (!is_int($value)) {
            $this->invalid($key, 'type');
        }
        if ($value < $min || $value > $max) {
            $this->invalid($key, 'range');
        }
        return $value;
    }
    /**
     * 读取严格布尔值，不将 0、1 或字符串转换为布尔值。
     *
     * @param string $key 待验证字段的名称。
     * @return bool 校验后的 true 或 false。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $enabled = $input->boolean('enabled');
     */
    public function boolean(string $key): bool
    {
        $value = $this->required($key);
        if (!is_bool($value)) {
            $this->invalid($key, 'type');
        }
        return $value;
    }
}
