<?php

declare(strict_types=1);

namespace Loom;

/**
 * 给简单 SQL 标识符加 ANSI_QUOTES 双引号。不接受表达式、空格或引号。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $quoted = new SqlIdentifier();
 */
final class SqlIdentifier
{
    /**
     * 只接受字母或下划线开头的字母、数字、下划线标识符，再添加双引号。
     *
     * @param string $value 要以字母或下划线开头，其余只能是字母、数字或下划线。
     * @return string 两侧加双引号的标识符。不符合格式时抛 LogicException。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $column = SqlIdentifier::quote('created_at');
     */
    public static function quote(string $value): string
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $value)) {
            throw new \LogicException('Invalid SQL identifier');
        }
        return '"' . $value . '"';
    }
}
