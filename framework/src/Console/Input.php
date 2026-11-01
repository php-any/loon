<?php

declare(strict_types=1);

namespace Loom\Console;

/**
 * 保存命令签名解析后的参数与选项，按名称读取并拒绝未知名称。
 *
 * 构造参数：
 * - $arguments（array）：位置参数名称到解析后值的映射。
 * - $options（array）：选项名称到解析后值的映射。
 *
 * 返回值：使用 new 构造时得到 Input 实例；注册到容器时由容器管理实例的创建与复用。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $input = new \Loom\Console\Input(['name' => '小明'], ['force' => false]);
 */
final class Input
{
    private array $arguments;
    private array $options;
    /**
     * 初始化 Input 实例及其依赖。保存命令签名解析后的参数与选项，按名称读取并拒绝未知名称。
     *
     * @param array $arguments 位置参数名称到解析后值的映射。
     * @param array $options 选项名称到解析后值的映射。
     * @return void 无直接返回值；通过 new 表达式取得初始化后的实例。
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
     * 读取已经解析的位置参数，名称不在签名定义中时抛出 LogicException。
     *
     * @param string $name 签名中声明的参数或选项名称，不带 --。
     * @return mixed 参数值，可能为字符串、数组或默认值。
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
     * 读取已经解析的命名选项，名称未知时抛出 LogicException。
     *
     * @param string $name 签名中声明的参数或选项名称，不带 --。
     * @return mixed 选项值，布尔开关为 bool，带值选项为字符串、数组或默认值。
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
