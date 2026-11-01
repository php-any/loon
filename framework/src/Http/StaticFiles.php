<?php

declare(strict_types=1);

namespace Loom;

/**
 * 从指定根目录提供 /assets/ 下的静态资源，限制路径越界、请求方法和文件大小。
 *
 * 构造参数：
 * - $root（string）：项目根目录路径，配置和业务文件以此为基础定位。
 *
 * 返回值：使用 new 构造时得到 StaticFiles 实例；注册到容器时由容器管理实例的创建与复用。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $assets = new StaticFiles($config->path('public'));
 */
final class StaticFiles
{
    private string $root;
    /**
     * 初始化 StaticFiles 实例及其依赖。从指定根目录提供 /assets/ 下的静态资源，限制路径越界、请求方法和文件大小。
     *
     * @param string $root 项目根目录路径，配置和业务文件以此为基础定位。
     * @return void 无直接返回值；通过 new 表达式取得初始化后的实例。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $assets = new StaticFiles($config->path('public'));
     */
    public function __construct(string $root)
    {
        $this->root = $root;
    }
    /**
     * 解析 /assets/ 后的相对路径并返回文件内容；仅允许 GET/HEAD、根目录内文件且不超过 4 MiB。
     *
     * @param Request $request 当前 HTTP 请求；中间件修改属性时应传递请求副本。
     * @return Response 带推断 Content-Type 的文件响应；非法路径、方法或大小抛出 HttpError。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $response = $assets->handle($request);
     */
    public function handle(Request $request): Response
    {
        if (!str_starts_with($request->path(), '/assets/')) {
            throw new HttpError(404, 'Not Found');
        }
        if (!in_array($request->method(), ['GET', 'HEAD'], true)) {
            throw new HttpError(405, 'Method Not Allowed');
        }
        $root = realpath($this->root);
        if ($root === false) {
            throw new HttpError(404, 'Not Found');
        }
        $path = realpath($root . '/' . substr($request->path(), 8));
        if ($path === false || !str_starts_with(str_replace('\\', '/', $path), str_replace('\\', '/', $root) . '/') || !is_file($path)) {
            throw new HttpError(404, 'Not Found');
        }
        $types = ['css' => 'text/css', 'js' => 'text/javascript', 'svg' => 'image/svg+xml', 'png' => 'image/png', 'ico' => 'image/x-icon', 'webp' => 'image/webp', 'woff' => 'font/woff', 'woff2' => 'font/woff2', 'ttf' => 'font/ttf'];
        $type = $types[strtolower(pathinfo($path, PATHINFO_EXTENSION))] ?? 'application/octet-stream';
        if (filesize($path) > 4194304) {
            throw new HttpError(413, 'Asset too large');
        }
        return new Response(file_get_contents($path), 200, ['Content-Type' => $type]);
    }
}
