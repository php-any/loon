<?php

declare(strict_types=1);

namespace Loom;

/**
 * 对一份字段表做存在性、类型、长度和范围检查。失败时抛出 HTTP 422，详情里带字段名和原因。
 *
 * 构造参数：
 * - $values（array）：字段名到原始值的关联数组，通常来自已解码的 JSON 对象。
 *
 * 返回值：使用 new 构造时得到 Input 实例。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $input = new Input(['name' => '小明', 'age' => 18]);
 */
final class Input
{
    private array $values;
    /**
     * 保存待检查的字段表。构造时不校验字段，检查由后续方法执行。
     *
     * @param array $values 字段名到原始值的关联数组。
     * @return void 无直接返回值。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $input = new Input(['name' => '小明', 'age' => 18]);
     */
    public function __construct(array $values)
    {
        $this->values = $values;
    }
    /**
     * 判断字段名是否出现。值为 null 也算存在。
     *
     * @param string $key 要检查的字段名。
     * @return bool 字段名存在时为 true。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $hasName = $input->has('name');
     */
    public function has(string $key): bool
    {
        return array_key_exists($key, $this->values);
    }
    /**
     * 读取必填列表。关联数组、非数组或元素个数超过上限时以 list 失败。缺失和 null 分别报告 missing、null。
     *
     * @param string $key 要读取的字段名。
     * @param int $max 允许的最多元素个数。默认 100。
     * @return array 保持原元素和顺序的列表。
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
     * 拒绝白名单以外的字段。遇到未知字段时以 unknown 失败，不会删掉多余字段后继续。
     *
     * @param array $fields 允许出现的字段名列表。
     * @return self 通过检查后的当前实例。
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
     * 读取必填且非 null 的字段。键不存在时以 missing 失败，值为 null 时以 null 失败。
     *
     * @param string $key 要读取的字段名。
     * @return mixed 该字段的原始值。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
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
     * 抛出 HTTP 422，没有正常返回。详情包含 field 和 reason。
     *
     * @param string $key 出错的字段名，写入详情的 field。
     * @param string $reason 失败原因，例如 missing、null、type、length、range、list 或 unknown。
     * @return void 无正常返回，始终抛出 HttpError。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $this->invalid('age', 'range');
     */
    private function invalid(string $key, string $reason): void
    {
        throw new HttpError(422, 'Invalid input', ['field' => $key, 'reason' => $reason]);
    }
    /**
     * 读取字符串并按字节数检查闭区间长度。不 trim，也不把其它类型转成字符串。边界不合法时抛出 LogicException，类型或长度不对分别以 type、length 失败。
     *
     * @param string $key 要读取的字段名。
     * @param int $minBytes 允许的最少字节数，不能为负。默认 0。
     * @param int $maxBytes 允许的最多字节数，不能小于 minBytes。默认 4096。
     * @return string 通过检查的原字符串。
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
     * 读取整数并检查闭区间。不接受数字字符串。上限小于下限时抛出 LogicException，类型或越界分别以 type、range 失败。
     *
     * @param string $key 要读取的字段名。
     * @param int $min 允许的最小整数，包含边界。默认 PHP_INT_MIN。
     * @param int $max 允许的最大整数，包含边界。默认 PHP_INT_MAX。
     * @return int 通过检查的整数。
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
     * 读取布尔值。0、1 和字符串不会被转换成布尔值，类型不对时以 type 失败。
     *
     * @param string $key 要读取的字段名。
     * @return bool 字段中的 true 或 false。
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
