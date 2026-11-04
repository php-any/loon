<?php

declare(strict_types=1);

namespace Loom;

/**
 * 保存状态码、响应头和正文。JSON、HTML 和问题详情由静态方法构造。修改响应头时返回副本。
 *
 * 构造参数：
 * - $body（string）：原样保存的响应正文。
 * - $status（int）：HTTP 状态码。默认 200。
 * - $headers（array）：头名到字符串值的映射，名称按小写存储。默认 Content-Type 为 text/plain; charset=utf-8。
 *
 * 返回值：使用 new 构造时得到 Response 实例。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $response = new Response('ok');
 */
final class Response
{
    private int $status;
    private array $headers;
    private string $body;
    /**
     * 保存正文和状态码，并把响应头名称转成小写。不检查状态码范围，也不改写正文。
     *
     * @param string $body 原样保存的响应正文。
     * @param int $status HTTP 状态码。默认 200。
     * @param array $headers 头名到字符串值的映射。名称按小写存储。默认 Content-Type 为 text/plain; charset=utf-8。
     * @return void 无直接返回值。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $response = new Response('ok', 200, ['Content-Type' => 'text/plain; charset=utf-8']);
     */
    public function __construct(string $body, int $status = 200, array $headers = ['Content-Type' => 'text/plain; charset=utf-8'])
    {
        $this->body = $body;
        $this->status = $status;
        $this->headers = [];
        foreach ($headers as $name => $value) {
            $this->headers[strtolower($name)] = $value;
        }
    }
    /**
     * 把值编码成 JSON 正文，内容类型为 application/json; charset=utf-8。编码失败时抛出 JsonException。
     *
     * @param mixed $value 要编码进正文的值。
     * @param int $status 响应状态码。默认 200。
     * @return self 带 JSON 正文的响应。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $response = Response::json(['ok' => true], 201);
     */
    public static function json(mixed $value, int $status = 200): self
    {
        return new self(json_encode($value, JSON_THROW_ON_ERROR), $status, ['Content-Type' => 'application/json; charset=utf-8']);
    }
    /**
     * 用给定字符串做正文，内容类型为 text/html; charset=utf-8。不转义 HTML。
     *
     * @param string $html 直接写入正文的 HTML。调用方自行转义。
     * @param int $status 响应状态码。默认 200。
     * @return self 带 HTML 正文的响应。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $response = Response::html('<p>完成</p>');
     */
    public static function html(string $html, int $status = 200): self
    {
        return new self($html, $status, ['Content-Type' => 'text/html; charset=utf-8']);
    }
    /**
     * 生成 application/problem+json。正文固定包含 type、title 和 status。details 非空时写入 errors。编码失败时抛出 JsonException。
     *
     * @param int $status 问题响应的 HTTP 状态码，同时写入正文的 status。
     * @param string $title 问题标题，写入正文的 title。
     * @param array $details 附加错误信息。空数组时正文不含 errors。默认空数组。
     * @return self 问题详情响应。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $response = Response::problem(422, '输入无效', ['field' => 'name']);
     */
    public static function problem(int $status, string $title, array $details = []): self
    {
        $body = ['type' => 'about:blank', 'title' => $title, 'status' => $status];
        if ($details !== []) {
            $body['errors'] = $details;
        }
        return new self(json_encode($body, JSON_THROW_ON_ERROR), $status, ['Content-Type' => 'application/problem+json; charset=utf-8']);
    }
    /**
     * 克隆响应，并按小写头名写入或覆盖一个头。原实例不变。
     *
     * @param string $name 响应头名称，存储前转为小写。
     * @param string $value 这个头的字符串值。
     * @return self 更新了该头的副本。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $copy = $response->withHeader('Cache-Control', 'no-store');
     */
    public function withHeader(string $name, string $value): self
    {
        $copy = clone $this;
        $copy->headers[strtolower($name)] = $value;
        return $copy;
    }
    /**
     * 导出传输层使用的结构。headers 做成对象，避免 JSON 把字符串键编成列表。
     *
     * 参数：无。
     *
     * @return array 含整数 status、对象 headers 和字符串 body。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $envelope = $response->toArray();
     */
    public function toArray(): array
    {
        return ['status' => $this->status, 'headers' => (object) $this->headers, 'body' => $this->body];
    }
}
