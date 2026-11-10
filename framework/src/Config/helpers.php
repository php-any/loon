<?php

declare(strict_types=1);

if (!function_exists('env')) {
    /**
     * 读取环境变量。getenv 能看到 putenv 和 .env 的当前值，因此优先使用；没有该变量时再查 $_ENV、$_SERVER。未设置时返回默认值。空字符串是有效值。true、false、empty、null 以及两侧带引号的值按 Laravel 的 env() 转换。
     *
     * @param ?string $key 环境变量名。null 时直接返回默认值。
     * @param mixed $default 变量不存在时返回的值。传入闭包时再调用它。默认 null。
     * @return mixed 环境变量的值，或默认值。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $host = env('DB_HOST', '127.0.0.1');
     */
    function env(?string $key, mixed $default = null): mixed
    {
        if ($key === null || $key === '') {
            return $default instanceof \Closure ? $default() : $default;
        }
        $loaded = getenv($key);
        if ($loaded !== false) {
            $value = $loaded;
        } else {
            $value = null;
            $found = false;
            foreach ([$_ENV, $_SERVER] as $variables) {
                if (!is_array($variables) || !array_key_exists($key, $variables) || !is_scalar($variables[$key])) {
                    continue;
                }
                $found = true;
                $raw = $variables[$key];
                $value = is_bool($raw) ? ($raw ? 'true' : 'false') : (string)$raw;
                break;
            }
            if (!$found) {
                return $default instanceof \Closure ? $default() : $default;
            }
        }
        switch (strtolower((string)$value)) {
            case 'true':
            case '(true)':
                return true;
            case 'false':
            case '(false)':
                return false;
            case 'empty':
            case '(empty)':
                return '';
            case 'null':
            case '(null)':
                return null;
        }
        $text = (string)$value;
        $length = strlen($text);
        if ($length >= 2 && ($text[0] === '"' || $text[0] === "'") && $text[$length - 1] === $text[0]) {
            return substr($text, 1, -1);
        }
        return $text;
    }
}

if (!function_exists('loom_bool_value')) {
    /**
     * @param mixed $raw 配置或 env 原始值。
     */
    function loom_bool_value(mixed $raw, bool $default): bool
    {
        if (is_bool($raw)) {
            return $raw;
        }
        if (is_int($raw)) {
            return $raw !== 0;
        }
        $s = strtolower(trim((string)$raw));
        return match ($s) {
            '1', 'true', '(true)', 'yes', 'on' => true,
            '0', 'false', '(false)', 'no', 'off', '' => false,
            default => $default,
        };
    }
}

if (!function_exists('env_bool')) {
    /**
     * 读取环境变量并转为布尔值；兼容 true/false、1/0、yes/no、on/off（与 env() 未覆盖的 1/0 数字串不同）。
     *
     * @param ?string $key 环境变量名。
     * @param bool $default 变量不存在时的默认值。
     */
    function env_bool(?string $key, bool $default = false): bool
    {
        if ($key === null || $key === '') {
            return $default;
        }
        return loom_bool_value(env($key, $default), $default);
    }
}
