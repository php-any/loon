<?php

declare(strict_types=1);

namespace Loom;

// 检查直接声明的方法，不自动加载或执行业务代码。
/**
 * 用 token 找出某个类直接声明的方法体。不加载类，也不执行方法。
 *
 * 构造参数：无，本类型未声明构造参数。
 *
 * 返回值：本类型只有静态 methods，不需要为检查去构造实例。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $methods = GrpcSource::methods($source, $class);
 */
final class GrpcSource
{
    /**
     * 没有 token_get_all 时抛出 RuntimeException。丢掉空白和注释后，按类名最后一段、大小写不敏感地进入源码中的第一处 class。不核对命名空间。
     * 只收集该类花括号深度为 1 的 function。只有分号、没有方法体的声明跳过。同名方法以后出现的为准。
     * 返回小写方法名到方法体的映射，方法体不含花括号。继承和 trait 里的方法不在结果中。类没找到时返回空数组。
     *
     * @param string $source 完整 PHP 源码，不需要已经加载。
     * @param string $class 完整类名。定位时只用最后一段。
     * @return array 小写方法名到方法体字符串。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $methods = GrpcSource::methods(file_get_contents($path), \App\Grpc\Services\Invoice::class);
     */
    public static function methods(string $source, string $class): array
    {
        if (!function_exists('token_get_all')) {
            throw new \RuntimeException('Origami token_get_all is required for implementation diagnostics');
        }
        $parts = explode('\\', $class);
        $name = array_pop($parts);
        $tokens = [];
        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $tokens[] = $token;
        }
        $result = [];
        $inside = false;
        $depth = 0;
        $count = count($tokens);
        for ($i = 0;$i < $count;$i++) {
            $token = $tokens[$i];
            if (!$inside) {
                if (is_array($token) && $token[0] === T_CLASS && isset($tokens[$i + 1]) && is_array($tokens[$i + 1]) && strcasecmp($tokens[$i + 1][1], $name) === 0) {
                    while ($i < $count && $tokens[$i] !== '{') {
                        $i++;
                    }
                    $inside = true;
                    $depth = 1;
                }
                continue;
            }
            if ($token === '{') {
                $depth++;
            }
            if ($token === '}') {
                $depth--;
                if ($depth === 0) {
                    break;
                }
            }
            if ($depth !== 1 || !is_array($token) || $token[0] !== T_FUNCTION) {
                continue;
            }
            $i++;
            if (($tokens[$i] ?? null) === '&') {
                $i++;
            }
            if (!isset($tokens[$i]) || !is_array($tokens[$i]) || $tokens[$i][0] !== T_STRING) {
                continue;
            }
            $method = strtolower($tokens[$i][1]);
            while ($i < $count && $tokens[$i] !== '{' && $tokens[$i] !== ';') {
                $i++;
            }
            if (($tokens[$i] ?? null) !== '{') {
                continue;
            }
            $body = '';
            $braces = 1;
            while (++$i < $count) {
                $part = $tokens[$i];
                if ($part === '{') {
                    $braces++;
                }
                if ($part === '}') {
                    $braces--;
                    if ($braces === 0) {
                        break;
                    }
                }
                $body .= is_array($part) ? $part[1] : $part;
            }
            $result[$method] = $body;
        }
        return $result;
    }
}
