<?php

declare(strict_types=1);

namespace Loom;

/**
 * WebSocket 处理器契约。实现类在已升级的连接上收发，连接由调用方关闭。
 *
 * 构造参数：无，本类型未声明构造参数。
 *
 * 返回值：接口不能直接实例化。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $action($request, $connection);
 */
interface WebSocketAction
{
    /**
     * 在已经升级的连接上处理这一次会话。本方法的契约不包含关闭连接。
     *
     * @param Request $request 升级前的 HTTP 请求。
     * @param \Net\Websocket\Conn $connection 已升级、可收发消息的连接。
     * @return void 无返回值。输出通过 connection 完成。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $action($request, $connection);
     */
    public function __invoke(Request $request, \Net\Websocket\Conn $connection): void;
}

