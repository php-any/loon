<?php

declare(strict_types=1);

namespace Loom;

/**
 * 装配 HTTP 与 WebSocket 宿主，启动前运行数据库初始化及路由检查，并为请求和定时器隔离服务作用域。
 *
 * 构造参数：
 * - $scope（\Loom\ServiceScope）：当前执行单元拥有的服务作用域。
 * - $config（\Loom\Config）：项目配置实例，提供配置读取及项目路径解析。
 * - $network（\Loom\Network）：网络限制配置服务，读取并校验协议参数。
 * - $moduleRegistry（\Loom\ModuleRegistry）：项目模块注册表，负责发现并安装模块组件。
 *
 * 返回值：使用 new 构造时得到 Server 实例；注册到容器时由容器管理实例的创建与复用。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $services->get(Server::class)->run();
 */
final class Server
{
    private \Loom\ServiceScope $scope;

    private \Loom\Config $config;
    private \Loom\Network $network;
    private \Loom\ModuleRegistry $moduleRegistry;

    /**
     * 初始化 Server 实例及其依赖。装配 HTTP 与 WebSocket 宿主，启动前运行数据库初始化及路由检查，并为请求和定时器隔离服务作用域。
     *
     * @param \Loom\ServiceScope $scope 当前执行单元拥有的服务作用域。
     * @param \Loom\Config $config 项目配置实例，提供配置读取及项目路径解析。
     * @param \Loom\Network $network 网络限制配置服务，读取并校验协议参数。
     * @param \Loom\ModuleRegistry $moduleRegistry 项目模块注册表，负责发现并安装模块组件。
     * @return void 无直接返回值；通过 new 表达式取得初始化后的实例。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $services->get(Server::class)->run();
     */
    public function __construct(\Loom\ServiceScope $scope, \Loom\Config $config, \Loom\Network $network, \Loom\ModuleRegistry $moduleRegistry)
    {
        $this->scope = $scope;

        $this->config = $config;
        $this->network = $network;
        $this->moduleRegistry = $moduleRegistry;
    }

    /**
     * 读取 HTTP 选项、校验网络限制、安装请求回调，并由 Host 启动后台服务后阻塞运行原生 HTTP 服务。
     *
     * 参数：无。
     *
     * @return void 无返回值；迁移和路由检查各用独立作用域，用完即关闭。服务结束后停止定时器，命令作用域由调用方关闭。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $server->run();
     */
    public function run(): void
    {
        $services = $this->scope->services();
        $migration = $this->scope->fork();
        try {
            $migration->load(dirname(__DIR__, 2) . '/bootstrap/database.php');
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
                $scope->services()->get(\Loom\Server::class)->handle($native, $writer);
            } finally {
                $scope->close();
            }
        });
        // 只借一个作用域确认入口能返回应用，然后关掉。请求和定时器再各自 fork。
        $check = $this->scope->fork();
        try {
            $app = $check->load($http->entry);
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
     * 创建当前请求的 HTTP 运行适配器并处理原生请求。服务作用域由创建它的调用方关闭。
     *
     * @param mixed $native 原生 HTTP 请求对象，提供方法、URL、头与正文访问能力。
     * @param mixed $writer 原生响应写出对象，提供 header、writeHeader 和 write 操作。
     * @return void 无返回值，响应直接写入原生 writer。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $server->handle($native, $writer);
     */
    public function handle(mixed $native, mixed $writer): void
    {
        $scope = $this->scope;
        $services = $scope->services();
        $http = $services->get(\Loom\HttpServerOptions::class);
        $runtime = new HttpRuntime($services->get(\Loom\Network::class), $services->get(\Loom\RequestScope::class), static function () use ($scope, $http): Application {
            return $scope->load($http->entry);
        }, new StaticFiles($this->config->path('public')));
        $runtime->handle($native, $writer);
    }
}
