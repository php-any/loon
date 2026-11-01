<?php

declare(strict_types=1);

namespace Loom;

// 仅适用于 HTML 文本及带引号的普通属性，不适用于 URL、脚本或样式上下文。
/**
 * 将字符串转义为 UTF-8 HTML 文本或带引号普通属性可用的内容，不适用于 URL、脚本或样式上下文。
 *
 * @param string $value 夹具保存或使用的测试值；具体注入来源由参数属性声明。
 * @return string 使用 ENT_QUOTES 和 ENT_SUBSTITUTE 处理后的字符串。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example echo \Loom\escape('<示例>');
 */
function escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
