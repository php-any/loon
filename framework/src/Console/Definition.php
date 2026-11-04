<?php

declare(strict_types=1);

namespace Loom\Console;

/**
 * 把一条命令签名拆成命令名、位置参数和选项。数组参数必须放在最后，必填参数不能出现在可选参数后面。签名不合法时构造函数抛出 LogicException，不会留下半截定义。
 *
 * 构造参数：
 * - $signature（string）：整段签名，例如 greet {name} {title?} {--l|loud}。
 * - $description（string）：帮助里显示的用途，原样保存，不参与语法检查。
 *
 * 返回值：名称、参数和选项都已拆开的 Definition。
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
     * 去掉签名两端空白，抽出命令名，再逐个解析花括号字段。位置参数记下是否可选、是否数组、默认值和描述；选项记下是否带值、是否数组、默认值、描述和单字母别名。命令名、字段、别名、重复名称、占用 help 或 h、数组参数没有放在最后、必填参数放在可选参数之后，或默认值写法不对时，抛出 LogicException。不解析实际的 argv。
     *
     * @param string $signature 命令名加若干 {参数} 或 {--选项} 的整段文本。
     * @param string $description 帮助中的命令用途，不参与签名校验。
     * @return void 无返回值；名称、签名、描述、参数、选项和别名已写入属性。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $definition = new \Loom\Console\Definition('greet {name : 用户} {title?} {word?*} {--l|loud} {--tag=* : 标签}', '问候用户');
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
     * 按本签名把令牌分成位置参数和选项。单独的 -- 之后全部当作位置参数，不再识别选项。非字符串令牌、未知长短选项、布尔选项带了值、带值选项缺值、必填参数缺失或位置参数过多时抛出 InputError。选项以及没给出的可选参数使用签名里的默认值。
     *
     * @param array $tokens 不含命令名的参数和选项字符串。
     * @return Input 参数和选项都已填好的输入，缺省项带有签名默认值。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $input = $definition->parse(['--', '小明']);
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
