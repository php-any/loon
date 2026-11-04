<?php

declare(strict_types=1);

namespace Loom;

/**
 * 携带 HTTP 状态码和结构化详情的业务异常。请求处理层把它转换成问题响应。
 *
 * 构造参数：
 * - $status（int）：这个错误对应的 HTTP 状态码，例如 404 或 422。
 * - $message（string）：异常消息，处理层会把它用作问题标题。
 * - $details（array）：随错误保存的结构化详情。默认空数组。
 *
 * 返回值：使用 new 构造时得到 HttpError 实例。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example throw new HttpError(422, '输入无效', ['field' => 'name']);
 */
final class HttpError extends \RuntimeException
{
    public int $status;
    public array $details;

    /**
     * 用父类保存消息，并记下状态码和详情。这里不生成 HTTP 响应。
     *
     * @param int $status 这个错误对应的 HTTP 状态码，例如 404 或 422。
     * @param string $message 异常消息。处理层把它用作问题响应的标题。
     * @param array $details 结构化详情。默认空数组。
     * @return void 无直接返回值。
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
