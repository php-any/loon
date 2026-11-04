<?php

declare(strict_types=1);

namespace Loom;

/**
 * 启动 HTTP 服务前完成数据库初始化和入口检查，并为每个请求单独 fork 服务作用域。
 *
 * 构造参数：
 * - $scope（\Loom\ServiceScope）：启动命令持有的服务作用域。run 从它 fork 迁移、入口检查和每个请求。
 * - $config（\Loom\Config）：解析 public 等项目路径。
 * - $network（\Loom\Network）：读取 http 与 websocket 限制。
 * - $moduleRegistry（\Loom\ModuleRegistry）：安装模块服务，并提供定时器定义。
 *
 * 返回值：使用 new 构造时得到 Server 实例。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $server = new Server($scope, $config, $network, $moduleRegistry);
 */
final class Server
{
    private \Loom\ServiceScope $scope;

    private \Loom\Config $config;
    private \Loom\Network $network;
    private \Loom\ModuleRegistry $moduleRegistry;

    /**
     * 保存命令作用域、项目配置、网络限制和模块注册表。构造时不监听端口。
     *
     * @param \Loom\ServiceScope $scope 启动命令持有的服务作用域。
     * @param \Loom\Config $config 用来解析 public 目录的项目配置。
     * @param \Loom\Network $network 读取 http 与 websocket 限制的配置服务。
     * @param \Loom\ModuleRegistry $moduleRegistry 安装模块服务并提供定时器的注册表。
     * @return void 无直接返回值。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $server = new Server($scope, $config, $network, $moduleRegistry);
     */
    public function __construct(\Loom\ServiceScope $scope, \Loom\Config $config, \Loom\Network $network, \Loom\ModuleRegistry $moduleRegistry)
    {
        $this->scope = $scope;

        $this->config = $config;
        $this->network = $network;
        $this->moduleRegistry = $moduleRegistry;
    }

    /**
     * 在独立作用域中执行数据库初始化，安装模块服务，检查地址和入口后阻塞运行 HTTP 服务。地址或入口缺失、地址不是 host:port、入口返回的不是 Application 时抛出 LogicException。网络限制不合法时抛出 InvalidArgumentException。迁移和入口检查的作用域用完即关闭。
     *
     * 参数：无。
     *
     * @return void 无返回值。命令作用域不在这里关闭。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $server->run();
     */
    public function run(): void
    {
        $services = $this->scope->container();
        $migration = $this->scope->fork();
        try {
            \Loom\ModelContext::during($migration, static function () use ($migration): void {
                $migration->load(dirname(__DIR__, 2) . '/bootstrap/database.php');
            });
        } finally {
            $migration->close();
        }
        // 先安装模块服务，应用才能在监听前替换 HTTP 选项或登记后台服务。
        $this->moduleRegistry->configured()->services($services);
        if ($services->lifetime(\Loom\HttpServerOptions::class) === null) {
            $services->options(\Loom\HttpServerOptions::class, 'http');
        }
        $http = $services->get(\Loom\HttpServerOptions::class);
        if (!isset($http->address, $http->entry) || !is_string($http->address) || $http->address === '' || !is_string($http->entry) || $http->entry === '') {
            throw new \LogicException('HTTP options require address and entry');
        }
        $this->network->limits('websocket');
        $address = parse_url('http://' . $http->address);
        if (!is_array($address) || !isset($address['host'], $address['port'])) {
            throw new \LogicException('HTTP address requires host:port');
        }
        $server = new \Net\Http\Server($address['host'], $address['port']);
        $server->configure($this->network->limits('http'));
        $parentScope = $this->scope;
        $server->any(static function ($native, $writer) use ($parentScope): void {
            $scope = $parentScope->fork();
            try {
                \Loom\ModelContext::during($scope, static function () use ($scope, $native, $writer): void {
                    $scope->container()->get(\Loom\Server::class)->handle($native, $writer);
                });
            } finally {
                $scope->close();
            }
        });
        // 只借一个作用域确认入口能返回应用，然后关掉。请求和定时器再各自 fork。
        $check = $this->scope->fork();
        try {
            $app = \Loom\ModelContext::during($check, static function () use ($check, $http): mixed {
                return $check->load($http->entry);
            });
            if (!($app instanceof Application)) {
                throw new \LogicException('HTTP entry must return an Application');
            }
        } finally {
            $check->close();
        }
        $timers = $this->moduleRegistry->configured()->timers($services);
        $limits = json_encode($this->network->limits('http'), JSON_THROW_ON_ERROR);
        $host = $services->get(\Loom\Host::class);
        $host->service(new \Loom\TimerHostedService($timers));
        echo 'Loom: http://' . $http->address . "\n";
        $host->run(static function () use ($server, $http, $limits): void {
            \Loom\Native\serveHttp($server, $http->address, $limits);
        });
    }
    /**
     * 用当前作用域中的网络和请求作用域组装 HttpRuntime，并处理这一次原生请求。public 目录来自配置。作用域由调用方关闭。
     *
     * @param mixed $native 原生 HTTP 请求。
     * @param mixed $writer 原生响应写入器。
     * @return void 无返回值。响应由 HttpRuntime 写入 writer。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $server->handle($native, $writer);
     */
    public function handle(mixed $native, mixed $writer): void
    {
        $scope = $this->scope;
        $services = $scope->container();
        $http = $services->get(\Loom\HttpServerOptions::class);
        $runtime = new HttpRuntime($services->get(\Loom\Network::class), $services->get(\Loom\RequestScope::class), static function () use ($scope, $http): Application {
            return $scope->load($http->entry);
        }, new StaticFiles($this->config->path('public')));
        $runtime->handle($native, $writer);
    }
}
