<?php

declare(strict_types=1);

namespace Loom;

/**
 * 校验简单 SQL 标识符并添加 ANSI_QUOTES 双引号，避免将任意表达式当作列名。
 *
 * 构造参数：无，本类型未声明构造参数。
 *
 * 返回值：使用 new 构造时得到 SqlIdentifier 实例；注册到容器时由容器管理实例的创建与复用。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $column = SqlIdentifier::quote('created_at');
 */
final class SqlIdentifier
{
    /**
     * 只接受字母或下划线开头的字母、数字、下划线标识符，再添加双引号。
     *
     * @param string $value 夹具保存或使用的测试值；具体注入来源由参数属性声明。
     * @return string 可拼入 SQL 的带引号标识符；无效输入抛出 InvalidArgumentException。
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
