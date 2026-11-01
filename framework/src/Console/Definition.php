<?php

declare(strict_types=1);

namespace Loom\Console;

/**
 * 解析类 Artisan 命令签名，支持可选参数、默认值、数组参数、选项别名及布尔开关。
 *
 * 构造参数：
 * - $signature（string）：命令签名，例如 greet {name} {title?} {--l|loud}；数组参数必须放最后。
 * - $description（string）：帮助信息显示的命令用途。
 *
 * 返回值：使用 new 构造时得到 Definition 实例；注册到容器时由容器管理实例的创建与复用。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $definition = new \Loom\Console\Definition('greet {name} {--l|loud}', '问候用户');
 */
final class Definition
{
    public string $name;
    public string $signature;
    public string $description;
    public array $arguments = [];
    public array $options = [];
    private array $aliases = [];
    /**
     * 初始化 Definition 实例及其依赖。解析类 Artisan 命令签名，支持可选参数、默认值、数组参数、选项别名及布尔开关。
     *
     * @param string $signature 命令签名，例如 greet {name} {title?} {--l|loud}；数组参数必须放最后。
     * @param string $description 帮助信息显示的命令用途。
     * @return void 无直接返回值；通过 new 表达式取得初始化后的实例。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $definition = new \Loom\Console\Definition('greet {name} {--l|loud}', '问候用户');
     */
    public function __construct(string $signature, string $description)
    {
        $this->signature = trim($signature);
        $this->description = $description;
        if (!preg_match('/^(_?[a-z][a-z0-9:_-]*)(.*)$/sD', $this->signature, $match)) {
            throw new \LogicException('命令签名无效');
        }
        $this->name = $match[1];
        $tail = $match[2];
        if (trim(preg_replace('/\{[^{}]+\}/', '', $tail)) !== '') {
            throw new \LogicException('命令签名字段无效');
        }
        preg_match_all('/\{([^{}]+)\}/', $tail, $fields);
        $optional = false;
        $variadic = false;
        foreach ($fields[1] as $field) {
            $pieces = preg_split('/\s+:\s*/', $field, 2);
            $spec = trim($pieces[0]);
            $description = trim($pieces[1] ?? '');
            if (substr($spec, 0, 2) === '--') {
                $parts = explode('=', substr($spec, 2), 2);
                $names = explode('|', $parts[0]);
                if (count($names) > 2) {
                    throw new \LogicException('选项别名无效');
                }
                $alias = count($names) === 2 ? $names[0] : '';
                $name = count($names) === 2 ? $names[1] : $names[0];
                if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_-]*$/D', $name) || in_array($name, ['help'], true) || isset($this->options[$name])) {
                    throw new \LogicException('选项无效或重复：' . $name);
                }
                if ($alias !== '') {
                    if (!preg_match('/^[a-zA-Z]$/D', $alias) || $alias === 'h' || isset($this->aliases[$alias])) {
                        throw new \LogicException('选项别名无效或重复');
                    } $this->aliases[$alias] = $name;
                }
                $value = count($parts) === 2;
                $array = $value && $parts[1] === '*';
                $this->options[$name] = ['value' => $value, 'array' => $array, 'default' => $array ? [] : ($value ? ($parts[1] === '' ? null : $parts[1]) : false), 'description' => $description, 'alias' => $alias];
            } else {
                if ($variadic) {
                    throw new \LogicException('数组参数必须放在最后');
                }
                $parts = explode('=', $spec, 2);
                $name = $parts[0];
                $array = substr($name, -1) === '*';
                if ($array) {
                    $name = substr($name, 0, -1);
                }
                $isOptional = substr($name, -1) === '?';
                if ($isOptional) {
                    $name = substr($name, 0, -1);
                }
                if (count($parts) === 2) {
                    if ($array || $isOptional) {
                        throw new \LogicException('参数默认值无效');
                    } $isOptional = true;
                }
                if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_-]*$/D', $name) || isset($this->arguments[$name])) {
                    throw new \LogicException('参数无效或重复');
                }
                if ($optional && !$isOptional) {
                    throw new \LogicException('必填参数不能放在可选参数之后');
                }
                $optional = $isOptional;
                $variadic = $array;
                $this->arguments[$name] = ['optional' => $isOptional, 'array' => $array, 'default' => $array ? [] : ($parts[1] ?? null), 'description' => $description];
            }
        }
    }
    /**
     * 按照签名解析令牌列表，支持 -- 终止选项解析，拒绝未知选项、缺值或多余参数。
     *
     * @param array $tokens 不包含命令名的命令行字符串列表。
     * @return Input 参数及选项均已填入默认值的 Console\Input。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $input = $definition->parse(['小明', '--loud']);
     */
    public function parse(array $tokens): Input
    {
        $options = [];
        foreach ($this->options as $name => $definition) {
            $options[$name] = $definition['default'];
        }
        $positionals = [];
        $end = false;
        for ($i = 0; $i < count($tokens); $i++) {
            $token = $tokens[$i];
            if (!is_string($token)) {
                throw new InputError('命令参数必须是字符串');
            }
            if (!$end && $token === '--') {
                $end = true;
                continue;
            }
            if (!$end && strlen($token) > 1 && $token[0] === '-' && !preg_match('/^-\d+(\.\d+)?$/D', $token)) {
                $provided = false;
                $value = null;
                if (substr($token, 0, 2) === '--') {
                    $parts = explode('=', substr($token, 2), 2);
                    $name = $parts[0];
                    $provided = count($parts) === 2;
                    $value = $parts[1] ?? null;
                } else {
                    $alias = $token[1];
                    if (!isset($this->aliases[$alias])) {
                        throw new InputError('未知选项：-' . $alias);
                    } $name = $this->aliases[$alias];
                    if (strlen($token) > 2) {
                        $provided = true;
                        $value = substr($token, 2);
                        if ($value[0] === '=') {
                            $value = substr($value, 1);
                        }
                    }
                }
                if (!isset($this->options[$name])) {
                    throw new InputError('未知选项：--' . $name);
                }
                $definition = $this->options[$name];
                if (!$definition['value']) {
                    if ($provided) {
                        throw new InputError('布尔选项不能指定值：--' . $name);
                    } $options[$name] = true;
                    continue;
                }
                if (!$provided) {
                    if (!isset($tokens[$i + 1]) || $tokens[$i + 1] === '--' || (substr($tokens[$i + 1], 0, 1) === '-' && !preg_match('/^-\d+(\.\d+)?$/D', $tokens[$i + 1]))) {
                        throw new InputError('缺少选项值：--' . $name);
                    } $value = $tokens[++$i];
                }
                if ($definition['array']) {
                    $options[$name][] = $value;
                } else {
                    $options[$name] = $value;
                }
            } else {
                $positionals[] = $token;
            }
        }
        $arguments = [];
        $index = 0;
        foreach ($this->arguments as $name => $definition) {
            if ($definition['array']) {
                $value = array_slice($positionals, $index);
                $index = count($positionals);
                if (!$definition['optional'] && $value === []) {
                    throw new InputError('缺少必填参数：' . $name);
                }
            } elseif ($index < count($positionals)) {
                $value = $positionals[$index++];
            } elseif (!$definition['optional']) {
                throw new InputError('缺少必填参数：' . $name);
            } else {
                $value = $definition['default'];
            }
            $arguments[$name] = $value;
        }
        if ($index < count($positionals)) {
            throw new InputError('参数数量过多');
        }
        return new Input($arguments, $options);
    }
}
