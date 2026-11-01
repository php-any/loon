<?php

declare(strict_types=1);

namespace Loom;

/**
 * 保存状态码、响应头与正文；提供 JSON、HTML 和问题详情响应工厂，修改响应头时返回副本。
 *
 * 构造参数：
 * - $body（string）：未经额外编码的响应正文。
 * - $status（int）：HTTP 状态码，例如 200、201 或 422。默认值为 200。
 * - $headers（array）：响应头名称到字符串值的映射，名称存储时转为小写。默认值为 ['Content-Type' => 'text/plain; charset=utf-8']。
 *
 * 返回值：使用 new 构造时得到 Response 实例；注册到容器时由容器管理实例的创建与复用。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $response = Response::json(['ok' => true])->withHeader('X-Request-Id', 'demo');
 */
final class Response
{
    private int $status;
    private array $headers;
    private string $body;
    /**
     * 初始化 Response 实例及其依赖。保存状态码、响应头与正文；提供 JSON、HTML 和问题详情响应工厂，修改响应头时返回副本。
     *
     * @param string $body 未经额外编码的响应正文。
     * @param int $status HTTP 状态码，例如 200、201 或 422。默认值为 200。
     * @param array $headers 响应头名称到字符串值的映射，名称存储时转为小写。默认值为 ['Content-Type' => 'text/plain; charset=utf-8']。
     * @return void 无直接返回值；通过 new 表达式取得初始化后的实例。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $response = Response::json(['ok' => true])->withHeader('X-Request-Id', 'demo');
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
     * 编码 JSON 并设置 application/json 内容类型；编码失败时抛出 JsonException。
     *
     * @param mixed $value 待 JSON 编码的数据；withHeader 中为头值。
     * @param int $status HTTP 状态码，例如 200、201 或 422。默认值为 200。
     * @return self 包含 JSON 正文的响应实例。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $response = Response::json(['ok' => true], 201);
     */
    public static function json(mixed $value, int $status = 200): self
    {
        return new self(json_encode($value, JSON_THROW_ON_ERROR), $status, ['Content-Type' => 'application/json; charset=utf-8']);
    }
    /**
     * 构造 UTF-8 HTML 响应，不自动转义传入内容。
     *
     * @param string $html 已完成必要转义的 HTML 字符串。
     * @param int $status HTTP 状态码，例如 200、201 或 422。默认值为 200。
     * @return self 包含 HTML 正文的响应实例。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $response = Response::html('<p>完成</p>');
     */
    public static function html(string $html, int $status = 200): self
    {
        return new self($html, $status, ['Content-Type' => 'text/html; charset=utf-8']);
    }
    /**
     * 构造 application/problem+json 错误响应，非空详情放入 errors 字段。
     *
     * @param int $status HTTP 状态码，例如 200、201 或 422。
     * @param string $title 面向调用方的问题标题。
     * @param array $details 错误详情，非空时写入问题响应的 errors 字段。默认值为 []。
     * @return self 含 type、title、status 及可选 errors 的响应。
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
     * 克隆响应并按小写名称写入或覆盖一个响应头。
     *
     * @param string $name 要设置的响应头名称。
     * @param string $value 待 JSON 编码的数据；withHeader 中为头值。
     * @return self 更新响应头后的副本。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $response = $response->withHeader('Cache-Control', 'no-store');
     */
    public function withHeader(string $name, string $value): self
    {
        $copy = clone $this;
        $copy->headers[strtolower($name)] = $value;
        return $copy;
    }
    /**
     * 将响应转换为传输层和任务入口约定的结构。
     *
     * 参数：无。
     *
     * @return array 含 status 整数、headers 对象、body 字符串的数组。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $envelope = $response->toArray();
     */
    public function toArray(): array
    {
        return ['status' => $this->status, 'headers' => (object) $this->headers, 'body' => $this->body];
    }
}

// 一个容器仅属于一次应用执行。工厂必须显式声明；
// 循环依赖解析以及重新绑定已构造的服务均视为错误。
