<?php

declare(strict_types=1);

namespace Loom\Grpc;

/**
 * 携带 gRPC 命名状态的业务异常，Dispatcher 将其转换为结构化错误信封。
 *
 * 构造参数：
 * - $status（string）：gRPC 命名状态，例如 invalid_argument、not_found、permission_denied 或 unimplemented。
 * - $message（string）：返回客户端的业务错误说明，不应包含内部凭据。
 *
 * 返回值：使用 new 构造时得到 RpcException 实例；注册到容器时由容器管理实例的创建与复用。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example throw new \Loom\Grpc\RpcException('not_found', '记录不存在');
 */
final class RpcException extends \RuntimeException
{
    public string $status;

    /**
     * 初始化 RpcException 实例及其依赖。携带 gRPC 命名状态的业务异常，Dispatcher 将其转换为结构化错误信封。
     *
     * @param string $status gRPC 命名状态，例如 invalid_argument、not_found、permission_denied 或 unimplemented。
     * @param string $message 返回客户端的业务错误说明，不应包含内部凭据。
     * @return void 无直接返回值；通过 new 表达式取得初始化后的实例。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example throw new \Loom\Grpc\RpcException('not_found', '记录不存在');
     */
    public function __construct(string $status, string $message)
    {
        parent::__construct($message);
        $this->status = $status;
    }
}
