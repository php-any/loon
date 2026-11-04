<?php

declare(strict_types=1);

namespace Loom;

// 原生 I/O 适配器。应用创建由组合根提供。
/**
 * 处理一次原生 HTTP 调用：读正文、分流静态资源或应用、把异常写成响应，并关闭请求作用域。
 *
 * 构造参数：
 * - $network（\Loom\Network）：提供 http 正文大小等协议限制。
 * - $requestScope（\Loom\RequestScope）：本请求要打开和关闭的作用域。
 * - $application（callable）：无参回调，每次调用应返回 Application。
 * - $assets（StaticFiles）：处理 /assets/ 路径的静态文件对象。
 *
 * 返回值：使用 new 构造时得到 HttpRuntime 实例。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $runtime = new HttpRuntime($network, $requestScope, $loader, $assets);
 */
final class HttpRuntime
{
    private \Loom\Network $network;
    private \Loom\RequestScope $requestScope;

    private mixed $application;
    private StaticFiles $assets;
    /**
     * 保存网络限制、请求作用域、应用加载回调和静态文件处理器。构造时不读取请求。
     *
     * @param \Loom\Network $network 提供 http 正文大小等协议限制。
     * @param \Loom\RequestScope $requestScope 本请求要打开和关闭的作用域。
     * @param callable $application 无参回调，每次调用应返回 Application。
     * @param StaticFiles $assets 处理 /assets/ 路径的静态文件对象。
     * @return void 无直接返回值。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $runtime = new HttpRuntime($network, $requestScope, $loader, $assets);
     */
    public function __construct(\Loom\Network $network, \Loom\RequestScope $requestScope, callable $application, StaticFiles $assets)
    {
        $this->network = $network;
        $this->requestScope = $requestScope;

        $this->application = $application;
        $this->assets = $assets;
    }
    /**
     * 打开请求作用域，读取正文并生成 Request。路径以 /assets/ 开头时交给静态文件，否则调用应用回调。HttpError 写成对应状态的问题 JSON，其它异常写成 500 并记到标准错误。HEAD 不写正文。结束时总会关闭作用域。
     *
     * @param mixed $native 原生 HTTP 请求，异常响应会读取它的 method。
     * @param mixed $writer 原生响应写入器，交给 HttpTransport。
     * @return void 无返回值。响应写入 writer。
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
