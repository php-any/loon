<?php

declare(strict_types=1);

namespace Loom;

// 传输状态属于单个原生请求，包括 WebSocket 所有权。
/**
 * 保存当前请求的原生 HTTP 写入器，并负责这次连接的响应写出和 WebSocket 升级。
 *
 * 构造参数：
 * - $network（\Loom\Network）：读取 websocket 等协议限制。
 * - $native（mixed）：当前 Origami 原生 HTTP 请求。
 * - $writer（mixed）：当前原生响应写入器，send 时调用 header、writeHeader 和 write。
 *
 * 返回值：使用 new 构造时得到 HttpTransport 实例。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $transport = new HttpTransport($network, $native, $writer);
 */
final class HttpTransport
{
    private \Loom\Network $network;

    private mixed $native;
    private mixed $writer;
    private bool $upgraded = false;
    /**
     * 保存网络限制、原生请求和响应写入器。升级标记初始为 false，构造时不写响应。
     *
     * @param \Loom\Network $network 读取 websocket 协议限制的配置服务。
     * @param mixed $native 当前 Origami 原生 HTTP 请求，升级时传给 Websocket\upgrade。
     * @param mixed $writer 原生响应写入器，send 时调用 header、writeHeader 和 write。
     * @return void 无直接返回值。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $transport = new HttpTransport($network, $native, $writer);
     */
    public function __construct(\Loom\Network $network, mixed $native, mixed $writer)
    {
        $this->network = $network;

        $this->native = $native;
        $this->writer = $writer;
    }
    /**
     * 把当前连接升级为 WebSocket，并套用 websocket 网络限制。缺少原生请求或写入器时抛出 LogicException。configure 失败会先关闭连接，再抛出原来的异常。
     *
     * 参数：无。
     *
     * @return \Net\Websocket\Conn 已套用限制的连接。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $connection = $transport->upgrade();
     */
    public function upgrade(): \Net\Websocket\Conn
    {
        if ($this->native === null || $this->writer === null) {
            throw new \LogicException('No HTTP transport');
        }
        $limits = $this->network->limits('websocket');
        $connection = \Net\Websocket\upgrade($this->native, $this->writer, false);
        $this->upgraded = true;
        try {
            $connection->configure($limits);
        } catch (\Throwable $error) {
            $connection->close();
            throw $error;
        }
        return $connection;
    }
    /**
     * 把响应头和正文写到原生写入器，并加上 X-Content-Type-Options: nosniff。已经升级的连接直接返回。正文超过 4 MiB 时抛出 RuntimeException。head 为 true 时不写正文。
     *
     * @param Response $response 要写出的响应。
     * @param bool $head 为 true 时只写状态码和响应头。默认 false。
     * @return void 无返回值。正文超过 4194304 字节时抛出 RuntimeException。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $transport->send(Response::json(['ok' => true]), false);
     */
    public function send(Response $response, bool $head = false): void
    {
        if ($this->upgraded) {
            return;
        }
        $writer = $this->writer;
        $data = $response->toArray();
        if (strlen($data['body']) > 4194304) {
            throw new \RuntimeException('Response too large');
        }
        foreach ($data['headers'] as $name => $value) {
            $writer->header($name, $value);
        }
        $writer->header('X-Content-Type-Options', 'nosniff');
        $writer->writeHeader($data['status']);
        if (!$head) {
            $writer->write($data['body']);
        }
    }
}
