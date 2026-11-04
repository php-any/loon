<?php

declare(strict_types=1);

namespace Loom\Grpc;

/**
 * 带命名 gRPC 状态的业务异常。Dispatcher 捕获后把它收成 error 信封。
 *
 * 构造参数：
 * - $status（string）：写入 status，供 Dispatcher 当作 error.code。
 * - $message（string）：交给父类 RuntimeException 的消息。
 *
 * 返回值：使用 new 构造时得到 RpcException。构造时不校验 status 是否为已知状态名。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example throw new \Loom\Grpc\RpcException('not_found', '记录不存在');
 */
final class RpcException extends \RuntimeException
{
    public string $status;

    /**
     * 用 message 调用父类构造，并把 status 存到公开属性。不对照已知的 gRPC 状态名。
     *
     * @param string $status 命名状态，例如 not_found。原样保存。
     * @param string $message 异常消息，也是父类 getMessage 的内容。
     * @return void 无直接返回值。
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
