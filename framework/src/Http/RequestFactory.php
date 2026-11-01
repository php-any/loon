<?php

declare(strict_types=1);

namespace Loom;

/**
 * 将 Origami 原生请求、服务器变量和正文转换为 Loom 请求，保留重复查询参数并限制正文大小。
 *
 * 构造参数：无，本类型未声明构造参数。
 *
 * 返回值：使用 new 构造时得到 RequestFactory 实例；注册到容器时由容器管理实例的创建与复用。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $request = RequestFactory::fromNative($native, $_SERVER, $body, 1048576);
 */
final class RequestFactory
{
    /**
     * 转换请求头和 URL 查询参数并构造请求；正文超出字节上限时抛出 HTTP 413。
     *
     * @param mixed $native 原生请求对象，需提供 fullUrl()、method()、path() 和 isSecure()。
     * @param array $server 服务器变量数组，例如 $_SERVER；从 HTTP_*、CONTENT_TYPE、CONTENT_LENGTH 提取头。
     * @param string $body 已读取的原始请求正文。
     * @param int $maxBodyBytes 正文允许的最大字节数。
     * @return Request 包含 origin、method、path、headers、query、body 的 Request。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $request = RequestFactory::fromNative($native, $_SERVER, $body, 1048576);
     */
    public static function fromNative(mixed $native, array $server, string $body, int $maxBodyBytes): Request
    {
        $headers = [];
        foreach ($server as $name => $value) {
            if (str_starts_with($name, 'HTTP_')) {
                $headers[strtolower(str_replace('_', '-', substr($name, 5)))] = [$value];
            } elseif ($name === 'CONTENT_TYPE' || $name === 'CONTENT_LENGTH') {
                $headers[strtolower(str_replace('_', '-', $name))] = [$value];
            }
        }
        $url = parse_url($native->fullUrl());
        $query = [];
        foreach (explode('&', $url['query'] ?? '') as $part) {
            if ($part === '') {
                continue;
            }
            $pair = explode('=', $part, 2);
            $query[urldecode($pair[0])][] = urldecode($pair[1] ?? '');
        }
        if (strlen($body) > $maxBodyBytes) {
            throw new HttpError(413, 'Request body too large');
        }
        $origin = ($native->isSecure() ? 'https://' : 'http://') . ($url['host'] ?? '') . (isset($url['port']) ? ':' . $url['port'] : '');
        return new Request(['method' => $native->method(), 'path' => $native->path(), 'headers' => $headers, 'query' => $query, 'body' => $body, 'origin' => $origin]);
    }
}
