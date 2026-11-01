<?php

declare(strict_types=1);

namespace Loom;

/**
 * 加载精简 dotenv 文件，支持引号和行尾注释，不插值；文件中的值覆盖进程环境变量。
 *
 * 构造参数：无，本类型未声明构造参数。
 *
 * 返回值：使用 new 构造时得到 Environment 实例；注册到容器时由容器管理实例的创建与复用。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example Environment::load($projectRoot . '/.env');
 */
final class Environment
{
    // 精简的 dotenv 格式：支持 KEY=value、引号和注释，不支持插值。
    /**
     * 逐行解析环境变量并调用 putenv；文件缺失时忽略，格式无效时报告行号。
     *
     * @param string $path 待匹配的请求路径，例如 /items。
     * @return void 无返回值；更新当前进程环境。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example Environment::load($projectRoot . '/.env');
     */
    public static function load(string $path): void
    {
        if (!is_file($path)) {
            return;
        }
        $lines = file($path, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            throw new \RuntimeException('Cannot read environment file');
        }
        foreach ($lines as $index => $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (str_starts_with($line, 'export ')) {
                $line = trim(substr($line, 7));
            }
            $parts = explode('=', $line, 2);
            $name = trim($parts[0]);
            if (count($parts) !== 2 || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name)) {
                throw new \InvalidArgumentException('Invalid environment entry at line ' . ($index + 1));
            }
            $value = trim($parts[1]);
            if ($value !== '' && ($value[0] === '"' || $value[0] === "'")) {
                $quote = $value[0];
                $end = strpos($value, $quote, 1);
                if ($end === false) {
                    throw new \InvalidArgumentException('Unclosed environment quote at line ' . ($index + 1));
                }
                $tail = trim(substr($value, $end + 1));
                if ($tail !== '' && !str_starts_with($tail, '#')) {
                    throw new \InvalidArgumentException('Invalid environment suffix at line ' . ($index + 1));
                }
                $value = substr($value, 1, $end - 1);
            } else {
                $value = rtrim(preg_replace('/\s+#.*$/', '', $value));
            }
            putenv($name . '=' . $value);
        }
    }
}
