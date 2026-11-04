<?php

declare(strict_types=1);

namespace Loom;

/**
 * 把 Origami 原生请求、服务器变量和正文字符串组装成 Request。重复的查询参数都保留，正文超过上限时拒绝。
 *
 * 构造参数：无，本类型未声明构造参数。
 *
 * 返回值：使用 new 构造时得到 RequestFactory 实例。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $request = RequestFactory::fromNative($native, $_SERVER, $body, 1048576);
 */
final class RequestFactory
{
    /**
     * 从服务器变量提取头，从完整 URL 解析查询参数，再构造 Request。同名查询参数追加到列表里。正文按字节超过上限时抛出 HTTP 413。
     *
     * @param mixed $native 原生请求，需提供 fullUrl()、method()、path() 和 isSecure()。
     * @param array $server 服务器变量。HTTP_*、CONTENT_TYPE 和 CONTENT_LENGTH 会变成小写头名。
     * @param string $body 已经读出的原始正文。
     * @param int $maxBodyBytes 正文允许的最大字节数，按 strlen 比较。
     * @return Request 带 origin、method、path、headers、query 和 body 的请求。
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
