<?php

declare(strict_types=1);

namespace Loom;

/**
 * 携带 HTTP 状态码和结构化详情的业务异常，由请求处理层转换为问题响应。
 *
 * 构造参数：
 * - $status（int）：待记录的 HTTP 状态码，例如 200 或 201。
 * - $message（string）：断言失败时抛出的说明。
 * - $details（array）：随错误返回的结构化详情数组。默认值为 []。
 *
 * 返回值：使用 new 构造时得到 HttpError 实例；注册到容器时由容器管理实例的创建与复用。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example throw new HttpError(422, '输入无效', ['field' => 'name']);
 */
final class HttpError extends \RuntimeException
{
    public int $status;
    public array $details;

    /**
     * 初始化 HttpError 实例及其依赖。携带 HTTP 状态码和结构化详情的业务异常，由请求处理层转换为问题响应。
     *
     * @param int $status 待记录的 HTTP 状态码，例如 200 或 201。
     * @param string $message 断言失败时抛出的说明。
     * @param array $details 随错误返回的结构化详情数组。默认值为 []。
     * @return void 无直接返回值；通过 new 表达式取得初始化后的实例。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example throw new HttpError(422, '输入无效', ['field' => 'name']);
     */
    public function __construct(int $status, string $message, array $details = [])
    {
        parent::__construct($message);
        $this->status = $status;
        $this->details = $details;
    }
}
