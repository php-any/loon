<?php

declare(strict_types=1);

namespace Loom;

/**
 * 声明 WebSocket 控制器契约，业务处理器接收请求及已升级连接，由外层负责最终关闭。
 *
 * 构造参数：无，本类型未声明构造参数。
 *
 * 返回值：接口不能直接实例化；由实现类提供本契约，操作结果见各方法的返回值。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $action($request, $connection);
 */
interface WebSocketAction
{
    /**
     * 在已升级的 WebSocket 连接上执行收发或推送业务。
     *
     * @param Request $request 当前 HTTP 请求；中间件修改属性时应传递请求副本。
     * @param \Net\Websocket\Conn $connection 已经升级的 WebSocket 连接，用于接收和发送消息。
     * @return void 无返回值，网络输出通过 connection 完成。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $action($request, $connection);
     */
    public function __invoke(Request $request, \Net\Websocket\Conn $connection): void;
}

// 不可变的注册选项：复用构建器不会修改其他同级路由。
