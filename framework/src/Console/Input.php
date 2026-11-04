<?php

declare(strict_types=1);

namespace Loom\Console;

/**
 * 按名称保存一次解析得到的位置参数和选项。读取未知名称时抛出 LogicException，不用 null 代替缺失项。
 *
 * 构造参数：
 * - $arguments（array）：位置参数名到值的映射。
 * - $options（array）：选项名到值的映射。
 *
 * 返回值：只按这两份映射读取的 Input。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $input = new \Loom\Console\Input(['name' => '小明'], ['force' => false]);
 */
final class Input
{
    private array $arguments;
    private array $options;
    /**
     * 原样保存位置参数映射和选项映射。不检查名称是否重复，也不补默认值。
     *
     * @param array $arguments 位置参数名到已解析值的映射。
     * @param array $options 选项名到已解析值的映射；布尔开关用 bool。
     * @return void 无返回值，两份映射已存入实例。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $input = new \Loom\Console\Input(['name' => '小明'], ['force' => false]);
     */
    public function __construct(array $arguments, array $options)
    {
        $this->arguments = $arguments;
        $this->options = $options;
    }
    /**
     * 按名称返回位置参数。名称不在构造时的参数映射里时抛出 LogicException。同名选项存在也不读取选项。
     *
     * @param string $name 位置参数名，不带花括号。
     * @return mixed 该参数保存的字符串、数组或默认值。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $name = $input->argument('name');
     */
    public function argument(string $name): mixed
    {
        if (!array_key_exists($name, $this->arguments)) {
            throw new \LogicException('未知参数：' . $name);
        } return $this->arguments[$name];
    }
    /**
     * 按名称返回选项。名称不在构造时的选项映射里时抛出 LogicException。不读取位置参数。
     *
     * @param string $name 选项全名，不带 --。
     * @return mixed 布尔开关、字符串、数组或该选项的默认值。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $force = $input->option('force');
     */
    public function option(string $name): mixed
    {
        if (!array_key_exists($name, $this->options)) {
            throw new \LogicException('未知选项：' . $name);
        } return $this->options[$name];
    }
}
