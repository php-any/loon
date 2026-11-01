<?php

declare(strict_types=1);

namespace Loom\Http\Middleware;

use Loom\HttpError;
use Loom\Middleware;
use Loom\Request;
use Loom\Response;

/**
 * 记录请求方法、路径、状态码和耗时的 JSON 行日志；日志失败不覆盖原响应或异常。
 *
 * 构造参数：
 * - $writer（?callable）：接收一行 JSON 字符串的日志回调；null 时写入标准错误流。默认值为 null。
 *
 * 返回值：使用 new 构造时得到 RequestLog 实例；注册到容器时由容器管理实例的创建与复用。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $logger = new RequestLog(static function (string $line): void { echo $line; });
 */
final class RequestLog implements Middleware
{
    private mixed $writer;

    /**
     * 初始化 RequestLog 实例及其依赖。记录请求方法、路径、状态码和耗时的 JSON 行日志；日志失败不覆盖原响应或异常。
     *
     * @param ?callable $writer 接收一行 JSON 字符串的日志回调；null 时写入标准错误流。默认值为 null。
     * @return void 无直接返回值；通过 new 表达式取得初始化后的实例。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $logger = new RequestLog(static function (string $line): void { echo $line; });
     */
    public function __construct(?callable $writer = null)
    {
        $this->writer = $writer;
    }

    /**
     * 执行后续处理，在 finally 中写入包含 time、event、method、path、status、duration_ms 的日志。
     *
     * @param Request $request 当前 HTTP 请求；中间件修改属性时应传递请求副本。
     * @param callable $next 下游处理器，接收 Request 并返回 Response。
     * @return Response 原处理链返回的响应；业务异常仍向上传播。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $response = $logger->handle($request, $next);
     */
    public function handle(Request $request, callable $next): Response
    {
        $started = microtime(true);
        $status = 500;
        try {
            $response = $next($request);
            $status = $response->toArray()['status'];
            return $response;
        } catch (HttpError $error) {
            $status = $error->status;
            throw $error;
        } finally {
            // 日志记录不得替换响应或掩盖原始异常。
            try {
                $line = json_encode([
                    'time' => gmdate('Y-m-d\TH:i:s\Z'),
                    'event' => 'http.request',
                    'method' => $request->method(),
                    'path' => $request->path(),
                    'status' => $status,
                    'duration_ms' => round(max(0, microtime(true) - $started) * 1000, 3),
                ], JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES) . "\n";
                if ($this->writer !== null) {
                    $writer = $this->writer;
                    $writer($line);
                } else {
                    $stream = @fopen('php://stderr', 'w');
                    if ($stream !== false) {
                        try {
                            @fwrite($stream, $line);
                        } finally {
                            fclose($stream);
                        }
                    }
                }
            } catch (\Throwable $loggingError) {
                // 日志目标写入失败不得中断 HTTP 处理。
            }
        }
    }
}
