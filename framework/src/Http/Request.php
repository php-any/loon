<?php

declare(strict_types=1);

namespace Loom;

/**
 * 封装请求方法、路径、头、查询参数和正文；路由参数及附加属性通过克隆保持原请求不变。
 *
 * 构造参数：
 * - $envelope（array）：请求信封：method、path、body 为字符串；headers 为小写头名到值列表的映射，query 为参数名到值列表的映射；origin 可选。
 *
 * 返回值：使用 new 构造时得到 Request 实例；注册到容器时由容器管理实例的创建与复用。
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
     * 初始化 Request 实例及其依赖。封装请求方法、路径、头、查询参数和正文；路由参数及附加属性通过克隆保持原请求不变。
     *
     * @param array $envelope 请求信封：method、path、body 为字符串；headers 为小写头名到值列表的映射，query 为参数名到值列表的映射；origin 可选。
     * @return void 无直接返回值；通过 new 表达式取得初始化后的实例。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $request = new Request(['method' => 'GET', 'path' => '/', 'headers' => [], 'query' => [], 'body' => '']);
     */
    public function __construct(array $envelope)
    {
        $this->envelope = $envelope;
    }
    /**
     * 读取请求自身的协议、主机和端口，供同源校验使用。
     *
     * 参数：无。
     *
     * @return string 请求来源字符串；信封未提供 origin 时为空字符串。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $origin = $request->origin();
     */
    public function origin(): string
    {
        return $this->envelope['origin'] ?? '';
    }
    /**
     * 读取请求使用的 HTTP 方法。
     *
     * 参数：无。
     *
     * @return string 信封中的 HTTP 方法字符串。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $method = $request->method();
     */
    public function method(): string
    {
        return $this->envelope['method'];
    }
    /**
     * 读取用于路由匹配的请求路径。
     *
     * 参数：无。
     *
     * @return string 信封中的路径，不在此方法解析查询字符串。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $path = $request->path();
     */
    public function path(): string
    {
        return $this->envelope['path'];
    }
    /**
     * 读取未经 JSON 解码的请求正文。
     *
     * 参数：无。
     *
     * @return string 原始正文字符串。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $json = $request->body();
     */
    public function body(): string
    {
        return $this->envelope['body'];
    }
    /**
     * 按不区分大小写的名称读取请求头的第一个值。
     *
     * @param string $name 要读取或设置的请求头、参数或属性名称，具体类别由方法决定。
     * @param string $default 目标不存在时使用的默认值，具体缺失判定见方法说明。默认值为 ''。
     * @return string 首个头值；不存在时返回 default。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $type = $request->header('Content-Type', 'text/plain');
     */
    public function header(string $name, string $default = ''): string
    {
        return $this->envelope['headers'][strtolower($name)][0] ?? $default;
    }
    /**
     * 读取同名查询参数的全部值，不将单值自动降为标量。
     *
     * @param string $name 要读取或设置的请求头、参数或属性名称，具体类别由方法决定。
     * @return array 参数值列表；不存在时返回空数组。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $tags = $request->query('tag');
     */
    public function query(string $name): array
    {
        return $this->envelope['query'][$name] ?? [];
    }
    /**
     * 读取路由匹配阶段绑定的占位参数；名称不存在时抛出 LogicException。
     *
     * @param string $name 要读取或设置的请求头、参数或属性名称，具体类别由方法决定。
     * @return string 指定参数的字符串值。
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
     * 克隆请求并替换完整的路由参数集合。
     *
     * @param array $parameters 路由占位符名称到字符串值的映射，例如 ['id' => '42']。
     * @return self 绑定新参数的请求副本，原实例不变。
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
     * 读取中间件或业务附加的属性，显式 null 不被默认值替代。
     *
     * @param string $name 要读取或设置的请求头、参数或属性名称，具体类别由方法决定。
     * @param mixed $default 目标不存在时使用的默认值，具体缺失判定见方法说明。默认值为 null。
     * @return mixed 属性值；键不存在时返回 default。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $input = $request->attribute('input');
     */
    public function attribute(string $name, mixed $default = null): mixed
    {
        return array_key_exists($name, $this->attributes) ? $this->attributes[$name] : $default;
    }
    /**
     * 克隆请求并设置一个附加属性。
     *
     * @param string $name 要读取或设置的请求头、参数或属性名称，具体类别由方法决定。
     * @param mixed $value 需要附加到请求上的属性值。
     * @return self 包含新属性的请求副本。
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
     * 验证 application/json 媒体类型并将对象正文转换为输入校验器；错误类型返回 415，非法正文返回 400。
     *
     * 参数：无。
     *
     * @return Input 包含已解码 JSON 对象字段的 Input。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $name = $request->json()->string('name', 1, 80);
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
