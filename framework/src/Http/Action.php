<?php

declare(strict_types=1);

namespace Loom;

/**
 * 定义普通 HTTP 控制器的调用契约：接收请求并返回响应。
 *
 * 构造参数：无，本类型未声明构造参数。
 *
 * 返回值：接口不能直接实例化；由实现类提供本契约，操作结果见各方法的返回值。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example final class Ping implements Action { public function __invoke(Request $request): Response { return Response::json(['ok' => true]); } }
 */
interface Action
{
    /**
     * 处理一条 HTTP 请求，由具体控制器实现业务逻辑。
     *
     * @param Request $request 当前 HTTP 请求；中间件修改属性时应传递请求副本。
     * @return Response HTTP 响应；不能返回未包装的数组或字符串。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $response = $action($request);
     */
    public function __invoke(Request $request): Response;
}

// 模板是应用管理的 PHP 文件。输入值保留在 $data 中，
// 避免覆盖局部变量；文本需显式使用 Loom\escape 转义。
