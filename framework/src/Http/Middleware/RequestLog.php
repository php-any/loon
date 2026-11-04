<?php

declare(strict_types=1);

namespace Loom\Http\Middleware;

use Loom\HttpError;
use Loom\Middleware;
use Loom\Request;
use Loom\Response;

/**
 * 在请求处理结束后写一行 JSON 日志，内容为方法、路径、状态码和耗时。写日志失败不替换响应，也不掩盖原来的异常。
 *
 * 构造参数：
 * - $writer（?callable）：接收一行 JSON 字符串的回调。null 时写入标准错误。默认 null。
 *
 * 返回值：使用 new 构造时得到 RequestLog 实例。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $logger = new RequestLog(static function (string $line): void { echo $line; });
 */
final class RequestLog implements Middleware
{
    private mixed $writer;

    /**
     * 保存日志回调。null 留到 handle 时再写标准错误，构造时不打开流。
     *
     * @param ?callable $writer 接收一行 JSON 字符串的回调。null 时改写 php://stderr。默认 null。
     * @return void 无直接返回值。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $logger = new RequestLog(static function (string $line): void { echo $line; });
     */
    public function __construct(?callable $writer = null)
    {
        $this->writer = $writer;
    }

    /**
     * 调用下游并返回它的响应。finally 中写一行 JSON，字段为 time、event、method、path、status 和 duration_ms。HttpError 记下它的状态码后继续抛出。其它异常按状态 500 记录并继续抛出。写日志失败被吞掉。
     *
     * @param Request $request 本次请求。日志使用它的方法和路径。
     * @param callable $next 下游回调，接收 Request 并返回 Response。
     * @return Response next 返回的响应。
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
