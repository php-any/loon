<?php

declare(strict_types=1);

namespace Loom;

/**
 * 根据目录和命名空间推导类名，不 require 源码，扫描时跳过符号链接并保持确定顺序。
 *
 * 构造参数：无，本类型未声明构造参数。
 *
 * 返回值：使用 new 构造时得到 ClassDiscovery 实例；注册到容器时由容器管理实例的创建与复用。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $classes = ClassDiscovery::scan($config->path('app'), 'App');
 */
final class ClassDiscovery
{
    /**
     * 递归扫描 PHP 文件并按相对路径推导类名；不实例化类，也不验证文件内声明是否匹配。
     *
     * @param string $directory 待扫描目录路径。
     * @param string $namespace 待发现类使用的命名空间前缀。
     * @return array 按排序后的目录遍历顺序得到的完整类名列表。
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
