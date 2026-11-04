<?php

declare(strict_types=1);

namespace Loom;

/**
 * 在隔离的输出缓冲区里执行 PHP 模板。模板只能通过 $data 读取传入的值，输出不会自动转义。
 *
 * 构造参数：无，本类型未声明构造参数。
 *
 * 返回值：使用 new 构造时得到 View 实例。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $html = View::render($templatePath, ['title' => '示例']);
 */
final class View
{
    /**
     * 在新的输出缓冲区中 require 模板，数据只通过 $data 传入，输出不做 HTML 转义。文件不存在，或执行后缓冲区层级不是进入时加一层，抛出 LogicException。异常时清掉多开的缓冲区；成功时返回这一层缓冲的内容。
     *
     * @param string $template 要执行的 PHP 模板文件路径。
     * @param array $data 模板通过 $data 读取的值。默认空数组。
     * @return string 模板写出的正文。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $html = View::render($templatePath, ['title' => '示例']);
     */
    public static function render(string $template, array $data = []): string
    {
        if (!is_file($template)) {
            throw new \LogicException('Template does not exist: ' . $template);
        }
        $level = ob_get_level();
        ob_start();
        try {
            $render = static function (string $template, array $data): void {
                require $template;
            };
            $render($template, $data);
            if (ob_get_level() !== $level + 1) {
                throw new \LogicException('Template changed output buffer ownership');
            }
            return ob_get_clean();
        } finally {
            while (ob_get_level() > $level) {
                if (!ob_end_clean()) {
                    break;
                }
            }
        }
    }
}

// 传输和连接方法由 Origami 标准库提供。
