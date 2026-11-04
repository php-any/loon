<?php

declare(strict_types=1);

namespace Loom;

/**
 * 按目录和命名空间拼出类名。扫描时跳过符号链接，同级路径按字符串排序，并且不加载文件。
 *
 * 构造参数：无，本类型未声明构造参数。
 *
 * 返回值：本类型只有静态 scan，不需要为扫描去构造实例。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $classes = ClassDiscovery::scan($config->path('app'), 'App');
 */
final class ClassDiscovery
{
    /**
     * 列出目录对应的类名。glob 失败时抛出 LogicException。得到的路径先按字符串排序。
     * 符号链接跳过。子目录用目录名追加命名空间后递归。扩展名为 php 的文件用文件名当作类名，其他文件忽略。
     * 不 require 文件，也不检查类声明是否与文件名一致。
     *
     * @param string $directory 要扫描的目录。
     * @param string $namespace 该目录对应的命名空间前缀。
     * @return array 完整类名。同级路径按字符串排序后逐个处理，目录递归展开，php 文件用文件名作为类名。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $classes = ClassDiscovery::scan($config->path('app'), 'App');
     */
    public static function scan(string $directory, string $namespace): array
    {
        $classes = [];
        $paths = glob($directory . '/*');
        if ($paths === false) {
            throw new \LogicException('Cannot scan ' . $directory);
        }
        sort($paths, SORT_STRING);
        foreach ($paths as $path) {
            if (is_link($path)) {
                continue;
            }
            if (is_dir($path)) {
                $classes = array_merge($classes, self::scan($path, $namespace . '\\' . basename($path)));
            } elseif (str_ends_with($path, '.php')) {
                $classes[] = $namespace . '\\' . basename($path, '.php');
            }
        }
        return $classes;
    }
}
