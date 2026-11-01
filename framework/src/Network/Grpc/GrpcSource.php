<?php

declare(strict_types=1);

namespace Loom;

// 检查直接声明的方法，不自动加载或执行业务代码。
/**
 * 使用 PHP token 检查指定类直接声明的方法体，不自动加载或执行业务源码。
 *
 * 构造参数：无，本类型未声明构造参数。
 *
 * 返回值：使用 new 构造时得到 GrpcSource 实例；注册到容器时由容器管理实例的创建与复用。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $methods = GrpcSource::methods(file_get_contents($path), $serviceClass);
 */
final class GrpcSource
{
    /**
     * 定位类声明并提取其直接方法体，忽略注释，不包括继承或 trait 提供的方法。
     *
     * @param string $source 待检查的完整 PHP 源码字符串。
     * @param string $class 目标完整类名，扫描按最后一段类名定位直接声明。
     * @return array 小写方法名到去除空白注释后的方法体字符串映射。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $methods = GrpcSource::methods(file_get_contents($path), $serviceClass);
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
