<?php

declare(strict_types=1);

namespace Loom;

// 原生 I/O 适配器。应用创建由组合根提供。
/**
 * 协调原生 HTTP 输入、静态资源、应用加载、异常响应和请求作用域清理。
 *
 * 构造参数：
 * - $network（\Loom\Network）：网络限制配置服务，读取并校验协议参数。
 * - $requestScope（\Loom\RequestScope）：负责建立单次请求生命周期的作用域服务。
 * - $application（callable）：无参应用加载回调，每次调用应返回 Loom\Application。
 * - $assets（StaticFiles）：仅负责 public 目录静态资源的处理器。
 *
 * 返回值：使用 new 构造时得到 HttpRuntime 实例；注册到容器时由容器管理实例的创建与复用。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $runtime = new HttpRuntime($network, $requestScope, fn () => $app, new StaticFiles($publicRoot));
 */
final class HttpRuntime
{
    private \Loom\Network $network;
    private \Loom\RequestScope $requestScope;

    private mixed $application;
    private StaticFiles $assets;
    /**
     * 初始化 HttpRuntime 实例及其依赖。协调原生 HTTP 输入、静态资源、应用加载、异常响应和请求作用域清理。
     *
     * @param \Loom\Network $network 网络限制配置服务，读取并校验协议参数。
     * @param \Loom\RequestScope $requestScope 负责建立单次请求生命周期的作用域服务。
     * @param callable $application 无参应用加载回调，每次调用应返回 Loom\Application。
     * @param StaticFiles $assets 仅负责 public 目录静态资源的处理器。
     * @return void 无直接返回值；通过 new 表达式取得初始化后的实例。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $runtime = new HttpRuntime($network, $requestScope, fn () => $app, new StaticFiles($publicRoot));
     */
    public function __construct(\Loom\Network $network, \Loom\RequestScope $requestScope, callable $application, StaticFiles $assets)
    {
        $this->network = $network;
        $this->requestScope = $requestScope;

        $this->application = $application;
        $this->assets = $assets;
    }
    /**
     * 读取正文，分发 /assets/ 静态请求或动态应用请求，将异常转换为响应并始终清理作用域。
     *
     * @param mixed $native 原生 HTTP 请求对象。
     * @param mixed $writer 原生 HTTP 响应写入器。
     * @return void 无返回值；响应直接写入 writer。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $runtime->handle($native, $writer);
     */
    public function handle(mixed $native, mixed $writer): void
    {
        $transport = new HttpTransport($this->network, $native, $writer);
        $this->requestScope->open($transport);
        try {
            set_time_limit(10);
            $body = file_get_contents('php://input');
            if ($body === false) {
                throw new HttpError(400, 'Cannot read request body');
            }
            $request = RequestFactory::fromNative($native, $_SERVER, $body, $this->network->limits('http')['maxBodyBytes']);
            if (str_starts_with($request->path(), '/assets/')) {
                $response = $this->assets->handle($request);
            } else {
                $loader = $this->application;
                $app = $loader();
                $response = $app->handle($request);
            }
            $transport->send($response, $request->method() === 'HEAD');
        } catch (HttpError $error) {
            $transport->send(Response::json(['type' => 'about:blank', 'title' => $error->getMessage(), 'status' => $error->status], $error->status), $native->method() === 'HEAD');
        } catch (\Throwable $error) {
            fwrite(fopen('php://stderr', 'w'), $error->getMessage() . "\n");
            $transport->send(Response::json(['type' => 'about:blank', 'title' => 'Internal Server Error', 'status' => 500], 500), $native->method() === 'HEAD');
        } finally {
            $this->requestScope->close();
        }
    }
}
