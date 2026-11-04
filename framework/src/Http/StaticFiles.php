<?php

declare(strict_types=1);

namespace Loom;

/**
 * 从指定根目录提供 /assets/ 下的文件。拒绝越出根目录的路径、非 GET/HEAD 方法和超过 4 MiB 的文件。
 *
 * 构造参数：
 * - $root（string）：静态文件根目录。请求路径去掉 /assets/ 后相对这个目录查找。
 *
 * 返回值：使用 new 构造时得到 StaticFiles 实例。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $assets = new StaticFiles($publicRoot);
 */
final class StaticFiles
{
    private string $root;
    /**
     * 保存静态文件根目录字符串。目录是否存在留到 handle 再用 realpath 检查。
     *
     * @param string $root 静态文件根目录，对应 /assets/ 之后的相对路径。
     * @return void 无直接返回值。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $assets = new StaticFiles($publicRoot);
     */
    public function __construct(string $root)
    {
        $this->root = $root;
    }
    /**
     * 读取 /assets/ 后的文件并返回 200 响应。路径不是该前缀、根目录或文件不存在、路径越出根目录时抛出 HTTP 404。方法不是 GET 或 HEAD 时抛出 405。文件大于 4 MiB 时抛出 413。按扩展名设置 Content-Type，未知扩展名为 application/octet-stream。
     *
     * @param Request $request 路径以 /assets/ 开头的请求。
     * @return Response 正文为文件内容的 200 响应。HEAD 也包含正文，由传输层决定是否写出。
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
