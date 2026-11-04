<?php

declare(strict_types=1);

namespace Loom;

/**
 * 读取一个 dotenv 文件并写入环境。支持引号和行尾注释，不展开变量。文件中的值覆盖同名的进程环境变量，同时写入 $_ENV 和 $_SERVER。
 *
 * 构造参数：无，本类型未声明构造参数。
 *
 * 返回值：本类型只有静态 load，不需要为加载去构造实例。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example Environment::load($projectRoot . '/.env');
 */
final class Environment
{
    // 精简的 dotenv 格式：支持 KEY=value、引号和注释，不支持插值。
    /**
     * 文件不存在时直接返回。读不到内容时抛出 RuntimeException。
     * 空行和以 # 开头的行跳过。行首的 export 前缀会去掉。名字和值按第一个等号拆开，名字必须是变量名，否则抛出带行号的 InvalidArgumentException。
     * 值以引号开头时必须有配对的结束引号，引号后只允许空白或 # 注释。无引号时去掉行尾的 # 注释。最后对该名字 putenv，并写入 $_ENV 与 $_SERVER。不处理插值。
     *
     * @param string $path dotenv 文件路径。
     * @return void 无返回值。
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
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }
    }
}
