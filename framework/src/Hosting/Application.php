<?php

declare(strict_types=1);

namespace Loom;

/**
 * 构建好的应用，对应 ASP.NET Core 的 WebApplication。持有根容器、配置、路由表、中间件管道、命令表和 gRPC 方法表。
 *
 * 映射方法只应在启动阶段（开始监听之前）调用。每个工作单元（HTTP 请求、TCP 消息、gRPC 调用、命令、队列与定时器节拍）
 * 由宿主各开一个 ServiceScope；闭包处理器的类类型参数从该作用域解析。
 *
 * @example
 * $app = Application::createBuilder(__DIR__)->build();
 * $app->use(RequestLog::class);
 * $app->mapGet('/ping', static fn (Request $request): Response => Response::json(['ok' => true]));
 * exit($app->run(array_slice($argv, 1)));
 */
final class Application
{
    /**
     * @internal 由 ApplicationBuilder::build() 调用。
     */
    public function __construct(
        public readonly ServiceProvider $services,
        public readonly Config $configuration,
        private readonly Router $router,
        private readonly HttpKernel $kernel,
        private readonly Console\Console $console,
        private readonly Grpc\Dispatcher $grpc,
    ) {
    }

    /**
     * 对应 WebApplication.CreateBuilder()。
     *
     * @param string $root 项目根目录。
     * @param ?string $environmentFile 先加载的 .env 文件；null 表示不加载。
     */
    public static function createBuilder(string $root, ?string $environmentFile = null): ApplicationBuilder
    {
        return new ApplicationBuilder($root, $environmentFile);
    }

    /**
     * 追加全局中间件：类名（从请求作用域解析）、Middleware 实例或 (Request, callable $next) 闭包。按登记顺序执行。
     */
    public function use(mixed $middleware): self
    {
        $this->kernel->middleware($middleware);
        return $this;
    }

    /**
     * 登记路由。$action 可以是 [控制器类, 方法]、可调用类名或闭包；闭包参数按 Invoker 绑定，Request 来自这次请求。
     */
    public function map(string $method, string $path, mixed $action, string $name = '', array $middleware = []): self
    {
        $this->router->route($method, $path, $action, $name, $middleware);
        return $this;
    }

    public function mapGet(string $path, mixed $action, string $name = '', array $middleware = []): self
    {
        return $this->map('GET', $path, $action, $name, $middleware);
    }

    public function mapPost(string $path, mixed $action, string $name = '', array $middleware = []): self
    {
        return $this->map('POST', $path, $action, $name, $middleware);
    }

    public function mapPut(string $path, mixed $action, string $name = '', array $middleware = []): self
    {
        return $this->map('PUT', $path, $action, $name, $middleware);
    }

    public function mapPatch(string $path, mixed $action, string $name = '', array $middleware = []): self
    {
        return $this->map('PATCH', $path, $action, $name, $middleware);
    }

    public function mapDelete(string $path, mixed $action, string $name = '', array $middleware = []): self
    {
        return $this->map('DELETE', $path, $action, $name, $middleware);
    }

    public function mapWebSocket(string $path, mixed $action, string $name = '', array $middleware = []): self
    {
        $this->router->websocket($path, $action, $name, $middleware);
        return $this;
    }

    /**
     * 路由分组，对应 MapGroup()。返回的登记器共享前缀和中间件，可继续 prefix()、withMiddleware()、get() 等。
     *
     * @example $app->mapGroup('/api', RequireToken::class)->get('/me', [MeController::class, 'show']);
     */
    public function mapGroup(string $prefix, mixed ...$middleware): RouteRegistrar
    {
        $group = $this->router->prefix($prefix);
        return $middleware === [] ? $group : $group->withMiddleware(...$middleware);
    }

    /**
     * 按路由名生成路径。
     */
    public function url(string $name, array $parameters = []): string
    {
        return $this->router->url($name, $parameters);
    }

    /**
     * 登记命令。处理器参数按 Invoker 绑定：Input、Output 来自这次调用，其余类类型参数从命令作用域解析。
     *
     * @example $app->mapCommand('greet {name}', static fn (Input $in, Output $out) => $out->line($in->argument('name')));
     */
    public function mapCommand(string $signature, callable $handler, string $description = ''): self
    {
        $this->console->command($signature, $handler, $description);
        return $this;
    }

    /**
     * 登记 gRPC 方法，优先于生成清单里的同名方法。
     *
     * @param string $method 完整 RPC 路径，如 /billing.Invoice/Get。
     * @param array{0: string, 1: string} $handler [服务类, 方法名]，服务从调用作用域解析。
     */
    public function mapGrpc(string $method, array $handler): self
    {
        $this->grpc->map($method, $handler);
        return $this;
    }

    /**
     * 在新作用域里跑一次 HTTP 管道并返回响应，不经过网络。测试和进程内调用使用。
     */
    public function handle(Request $request): Response
    {
        $kernel = $this->kernel;
        $path = $request->path();
        $context = \Loom\Monitoring\MonitoringContext::http($request->method(), $path);
        return $this->services->withMonitoredScope($context, static fn (ServiceScope $scope): Response => $kernel->handle($request, $scope));
    }

    /**
     * 在新作用域里执行一条命令并返回退出码。serve、tcp:serve 等长驻命令在这里阻塞。
     *
     * @param ?array $args 命令名和参数；null 表示读进程 argv。
     */
    public function run(?array $args = null): int
    {
        $console = $this->console;
        try {
            return $this->services->withMonitoredScope(
                \Loom\Monitoring\MonitoringContext::console($args[0] ?? 'list'),
                static fn (ServiceScope $scope): int => $console->run($args, $scope),
            );
        } catch (\Throwable $error) {
            $console->output()->error($error->getMessage());
            return 1;
        }
    }

    /**
     * 释放根容器创建的单例。
     */
    public function dispose(): void
    {
        $this->services->dispose();
    }
}
