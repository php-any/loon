<?php

declare(strict_types=1);

namespace Loom;

// 仅适用于 HTML 文本及带引号的普通属性，不适用于 URL、脚本或样式上下文。
/**
 * 把字符串转成可放进 HTML 文本或带引号普通属性的 UTF-8 文本。不处理 URL、脚本或样式上下文。
 *
 * @param string $value 要转义的原始字符串。
 * @return string htmlspecialchars 的结果，标志为 ENT_QUOTES 和 ENT_SUBSTITUTE，编码为 UTF-8。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $safe = \Loom\escape('<示例>');
 */
function escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
