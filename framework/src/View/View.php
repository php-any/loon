<?php

declare(strict_types=1);

namespace Loom;

/**
 * 在隔离局部作用域和受控输出缓冲区内执行 PHP 模板，模板数据只能通过 $data 读取。
 *
 * 构造参数：无，本类型未声明构造参数。
 *
 * 返回值：使用 new 构造时得到 View 实例；注册到容器时由容器管理实例的创建与复用。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $html = View::render($config->path('resources/views/demo/page.php'), ['title' => '示例']);
 */
final class View
{
    /**
     * 执行模板并收集输出；模板缺失或破坏缓冲区层级时抛出 LogicException，结束时清理新增缓冲区。
     *
     * @param string $template 已存在的 PHP 模板文件路径，调用方负责限定允许访问的模板。
     * @param array $data 模板使用的变量映射，通过 $data['键'] 读取，不展开为局部变量。默认值为 []。
     * @return string 模板产生的字符串；不会自动进行 HTML 转义。
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
