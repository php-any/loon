<?php

declare(strict_types=1);

namespace Loom;

/**
 * 普通 HTTP 控制器的调用契约。实现接收一条请求并返回响应。
 *
 * 构造参数：无，本类型未声明构造参数。
 *
 * 返回值：接口不能直接实例化。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $response = $action($request);
 */
interface Action
{
    /**
     * 处理一条 HTTP 请求并返回响应。具体控制器实现业务逻辑。
     *
     * @param Request $request 本次 HTTP 请求。
     * @return Response 该请求对应的 HTTP 响应。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $response = $action($request);
     */
    public function __invoke(Request $request): Response;
}

// 模板是应用管理的 PHP 文件。输入值保留在 $data 中，
// 避免覆盖局部变量；文本需显式使用 Loom\escape 转义。
