<?php

declare(strict_types=1);

namespace Loom;

/**
 * 保存请求方法、路径、头、查询参数和正文。路由参数和附加属性只写在克隆出的副本上。
 *
 * 构造参数：
 * - $envelope（array）：method、path、body 为字符串。headers 是小写头名到值列表，query 是参数名到值列表。origin 可省略。
 *
 * 返回值：使用 new 构造时得到 Request 实例。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $request = new Request(['method' => 'GET', 'path' => '/', 'headers' => [], 'query' => [], 'body' => '']);
 */
final class Request
{
    private array $envelope;
    private array $parameters = [];
    private array $attributes = [];

    /**
     * 保存请求信封。路由参数和附加属性初始为空，构造时不检查信封字段是否齐全。
     *
     * @param array $envelope method、path、body 为字符串。headers 是小写头名到值列表，query 是参数名到值列表。origin 可省略。
     * @return void 无直接返回值。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $request = new Request(['method' => 'GET', 'path' => '/', 'headers' => [], 'query' => [], 'body' => '']);
     */
    public function __construct(array $envelope)
    {
        $this->envelope = $envelope;
    }
    /**
     * 读取信封里的 origin，供同源校验使用。信封没有该键时返回空字符串。
     *
     * 参数：无。
     *
     * @return string 请求来源。未提供时为空字符串。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $origin = $request->origin();
     */
    public function origin(): string
    {
        return $this->envelope['origin'] ?? '';
    }
    /**
     * 读取信封中的 HTTP 方法。本方法不规范化大小写。
     *
     * 参数：无。
     *
     * @return string 信封里的 method 字符串。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $method = $request->method();
     */
    public function method(): string
    {
        return $this->envelope['method'];
    }
    /**
     * 读取信封中的请求路径。本方法不解析查询字符串。
     *
     * 参数：无。
     *
     * @return string 信封里的 path 字符串。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $path = $request->path();
     */
    public function path(): string
    {
        return $this->envelope['path'];
    }
    /**
     * 读取尚未解码的原始正文。
     *
     * 参数：无。
     *
     * @return string 信封里的 body 字符串。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $json = $request->body();
     */
    public function body(): string
    {
        return $this->envelope['body'];
    }
    /**
     * 按不区分大小写的名称读取第一个请求头。该头没有值时返回默认值。
     *
     * @param string $name 请求头名称，查找前转为小写。
     * @param string $default 该头没有值时返回的字符串。默认空字符串。
     * @return string 第一个头值，或 default。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $type = $request->header('Content-Type', 'text/plain');
     */
    public function header(string $name, string $default = ''): string
    {
        return $this->envelope['headers'][strtolower($name)][0] ?? $default;
    }
    /**
     * 读取同名查询参数的全部值。只有一个值时也留在列表里。
     *
     * @param string $name 查询参数名，按信封中的键精确匹配。
     * @return array 该参数的值列表。没有这个名字时为空数组。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $tags = $request->query('tag');
     */
    public function query(string $name): array
    {
        return $this->envelope['query'][$name] ?? [];
    }
    /**
     * 读取路由匹配时写入的占位参数。名称不在参数表中时抛出 LogicException。
     *
     * @param string $name 路由占位符名称，例如 id。
     * @return string 该占位符的字符串值。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $id = $request->parameter('id');
     */
    public function parameter(string $name): string
    {
        if (!array_key_exists($name, $this->parameters)) {
            throw new \LogicException('Unknown route parameter: ' . $name);
        }
        return $this->parameters[$name];
    }
    /**
     * 克隆请求，并用给出的映射整表替换路由参数。原实例不变。
     *
     * @param array $parameters 占位符名称到字符串值的完整映射，例如 ['id' => '42']。
     * @return self 带有新参数表的请求副本。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $matched = $request->withParameters(['id' => '42']);
     */
    public function withParameters(array $parameters): self
    {
        $copy = clone $this;
        $copy->parameters = $parameters;
        return $copy;
    }
    /**
     * 读取附加属性。键存在且值为 null 时返回 null，不用默认值代替。
     *
     * @param string $name 属性名。
     * @param mixed $default 属性名不存在时返回的值。默认 null。
     * @return mixed 已保存的属性值，或 default。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $input = $request->attribute('input');
     */
    public function attribute(string $name, mixed $default = null): mixed
    {
        return array_key_exists($name, $this->attributes) ? $this->attributes[$name] : $default;
    }
    /**
     * 克隆请求并写入一个附加属性。原实例的属性不变。
     *
     * @param string $name 要写入的属性名。
     * @param mixed $value 属性值，可以是 null。
     * @return self 带有该属性的请求副本。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $authenticated = $request->withAttribute('user', $user);
     */
    public function withAttribute(string $name, mixed $value): self
    {
        $copy = clone $this;
        $copy->attributes[$name] = $value;
        return $copy;
    }
    /**
     * 要求 Content-Type 去掉参数后为 application/json，再把对象正文解码成 Input。媒体类型不对抛出 HTTP 415。正文为空、不以 { 开头或 JSON 无法解码时抛出 HTTP 400。解码深度限制为 64。
     *
     * 参数：无。
     *
     * @return Input 用解码后的字段表构造的 Input。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $input = $request->json();
     */
    public function json(): Input
    {
        $type = strtolower(trim(explode(';', $this->header('content-type'))[0]));
        if ($type !== 'application/json') {
            throw new HttpError(415, 'Expected application/json');
        }
        $body = trim($this->body());
        if ($body === '' || $body[0] !== '{') {
            throw new HttpError(400, 'Expected a JSON object');
        }
        try {
            $values = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            throw new HttpError(400, 'Malformed JSON');
        }
        return new Input($values);
    }
}

// 显式输入契约区分字段缺失、null 和类型差异。
