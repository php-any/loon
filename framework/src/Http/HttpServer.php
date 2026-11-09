<?php

declare(strict_types=1);

namespace Loom;

/**
 * HTTP 宿主，对应 ASP.NET Core 的 Kestrel 加请求管道入口。每个请求打开一个 ServiceScope，在其中跑 HttpKernel，结束时释放作用域。
 *
 * /assets/ 路径直接交给 StaticFiles。HttpError 写成对应状态的问题 JSON，其它异常写成 500 并记到标准错误。HEAD 不写正文。
 *
 * Origami 在请求虚拟机里执行回调，请求内对脚本对象的写入只对该请求可见；路由表和单例要在 run() 之前准备好。
 *
 * @example $app->services->get(HttpServer::class)->run();
 */
final class HttpServer
{
    private StaticFiles $assets;

    public function __construct(
        private readonly ServiceProvider $services,
        private readonly HttpKernel $kernel,
        private readonly Network $network,
        private readonly HttpServerOptions $options,
        Config $config,
    ) {
        $this->assets = new StaticFiles($config->path('public'));
    }

    /**
     * 校验地址和限制后阻塞监听。地址缺失或不是 host:port 时抛出 LogicException，限制不合法时抛出 InvalidArgumentException。
     */
    public function run(): void
    {
        if (!isset($this->options->address) || $this->options->address === '') {
            throw new \LogicException('HTTP options require address');
        }
        $address = parse_url('http://' . $this->options->address);
        if (!is_array($address) || !isset($address['host'], $address['port'])) {
            throw new \LogicException('HTTP address requires host:port');
        }
        $this->network->limits('websocket');
        $limits = $this->network->limits('http');
        $server = new \Net\Http\Server($address['host'], $address['port']);
        $server->configure($limits);
        $listener = $this;
        $server->any(static function ($native, $writer) use ($listener): void {
            $listener->handle($native, $writer);
        });
        echo 'Loom: http://' . $this->options->address . "\n";
        \Loom\Native\serveHttp($server, $this->options->address, json_encode($limits, JSON_THROW_ON_ERROR));
    }

    /**
     * 在新作用域里处理一次原生请求，响应写入 writer。
     *
     * @param mixed $native 原生 HTTP 请求。
     * @param mixed $writer 原生响应写入器。
     */
    public function handle(mixed $native, mixed $writer): void
    {
        $listener = $this;
        $context = \Loom\Monitoring\MonitoringContext::http($native->method(), $native->path());
        $this->services->withMonitoredScope($context, static function (ServiceScope $scope) use ($listener, $native, $writer): void {
            $listener->respond($scope, $native, $writer);
        });
    }

    /**
     * @internal 由 handle() 在请求作用域里调用。
     */
    public function respond(ServiceScope $scope, mixed $native, mixed $writer): void
    {
        $transport = new HttpTransport($this->network, $native, $writer);
        $scope->get(RequestContext::class)->attach($transport);
        $monitoring = $scope->tryGet(\Loom\Monitoring\Monitoring::class);
        $started = microtime(true);
        $status = 500;
        try {
            set_time_limit(10);
            $body = file_get_contents('php://input');
            if ($body === false) {
                throw new HttpError(400, 'Cannot read request body');
            }
            $request = RequestFactory::fromNative($native, $_SERVER, $body, $this->network->limits('http')['maxBodyBytes']);
            $monitoring?->recordHttpInput([
                'method' => $request->method(),
                'path' => $request->path(),
                'body_bytes' => strlen($body),
            ]);
            $response = str_starts_with($request->path(), '/assets/')
                ? $this->assets->handle($request)
                : $this->kernel->handle($request, $scope);
            $status = $response->toArray()['status'];
            $monitoring?->recordHttpOutput([
                'status' => $status,
                'body_bytes' => strlen((string)$response->toArray()['body']),
                'duration_ms' => round(max(0, microtime(true) - $started) * 1000, 3),
            ]);
            $transport->send($response, $request->method() === 'HEAD');
        } catch (HttpError $error) {
            $status = $error->status;
            $monitoring?->recordHttpOutput([
                'status' => $status,
                'duration_ms' => round(max(0, microtime(true) - $started) * 1000, 3),
            ]);
            $transport->send(Response::json(['type' => 'about:blank', 'title' => $error->getMessage(), 'status' => $error->status], $error->status), $native->method() === 'HEAD');
        } catch (\Throwable $error) {
            $monitoring?->recordHttpOutput([
                'status' => 500,
                'duration_ms' => round(max(0, microtime(true) - $started) * 1000, 3),
            ]);
            fwrite(fopen('php://stderr', 'w'), $error->getMessage() . "\n");
            $transport->send(Response::json(['type' => 'about:blank', 'title' => 'Internal Server Error', 'status' => 500], 500), $native->method() === 'HEAD');
        }
    }
}
