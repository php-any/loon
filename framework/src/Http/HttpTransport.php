<?php

declare(strict_types=1);

namespace Loom;

// 传输状态属于单个原生请求，包括 WebSocket 所有权。
/**
 * 适配原生 HTTP 写入器与 WebSocket 升级操作，一个实例只属于当前请求。
 *
 * 构造参数：
 * - $network（\Loom\Network）：网络限制配置服务，读取并校验协议参数。
 * - $native（mixed）：当前 Origami 原生 HTTP 请求对象。
 * - $writer（mixed）：当前原生响应写入器，需支持 header()、writeHeader() 和 write()。
 *
 * 返回值：使用 new 构造时得到 HttpTransport 实例；注册到容器时由容器管理实例的创建与复用。
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
     * 初始化 HttpTransport 实例及其依赖。适配原生 HTTP 写入器与 WebSocket 升级操作，一个实例只属于当前请求。
     *
     * @param \Loom\Network $network 网络限制配置服务，读取并校验协议参数。
     * @param mixed $native 当前 Origami 原生 HTTP 请求对象。
     * @param mixed $writer 当前原生响应写入器，需支持 header()、writeHeader() 和 write()。
     * @return void 无直接返回值；通过 new 表达式取得初始化后的实例。
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
     * 将当前 HTTP 连接升级为 WebSocket，并应用 websocket 网络限制。
     *
     * 参数：无。
     *
     * @return \Net\Websocket\Conn 升级后的原生 WebSocket 连接；配置失败会关闭连接并抛出异常。
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
     * 将响应写入原生传输，自动添加 nosniff；HEAD 不写正文，已升级连接不再发送 HTTP 响应。
     *
     * @param Response $response 准备发送的 Loom 响应。
     * @param bool $head 是否为 HEAD 请求；true 时仅写状态码和响应头。默认值为 false。
     * @return void 无返回值；正文超过 4 MiB 时抛出 RuntimeException。
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
