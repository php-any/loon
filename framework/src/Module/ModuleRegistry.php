<?php

declare(strict_types=1);

namespace Loom;

// 运行时安装使用元数据；发现阶段绝不实例化应用服务。
/**
 * 消费发现器的元数据，为具体容器安装服务、路由、命令和任务，并为定时器创建隔离作用域。
 *
 * 构造参数：
 * - $discovery（ModuleDiscovery）：已收集或准备收集模块元数据的发现器。
 * - $config（Config）：项目配置实例，提供配置读取及项目路径解析。
 * - $scope（ServiceScope）：用于派生隔离容器的父服务作用域。
 *
 * 返回值：使用 new 构造时得到 ModuleRegistry 实例；注册到容器时由容器管理实例的创建与复用。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $modules = $services->get(ModuleRegistry::class)->configured(); $modules->services($services);
 */
final class ModuleRegistry
{
    private ModuleDiscovery $discovery;
    private Config $config;
    private ServiceScope $scope;
    /**
     * 初始化 ModuleRegistry 实例及其依赖。消费发现器的元数据，为具体容器安装服务、路由、命令和任务，并为定时器创建隔离作用域。
     *
     * @param ModuleDiscovery $discovery 已收集或准备收集模块元数据的发现器。
     * @param Config $config 项目配置实例，提供配置读取及项目路径解析。
     * @param ServiceScope $scope 用于派生隔离容器的父服务作用域。
     * @return void 无直接返回值；通过 new 表达式取得初始化后的实例。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $modules = $services->get(ModuleRegistry::class)->configured(); $modules->services($services);
     */
    public function __construct(ModuleDiscovery $discovery, Config $config, ServiceScope $scope)
    {
        $this->discovery = $discovery;
        $this->config = $config;
        $this->scope = $scope;
    }
    /**
     * 使用重新发现的配置模块构造注册器，复用配置和父作用域引用。
     *
     * 参数：无。
     *
     * @return self 装载配置模块元数据的 ModuleRegistry。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $modules = $modules->configured();
     */
    public function configured(): self
    {
        return new self($this->discovery->configured(), $this->config, $this->scope);
    }
    /**
     * 委托 ModuleDiscovery 读取类上的唯一属性注解。
     *
     * @param \ReflectionClass $class 待发现的模块或组件完整类名；attribute 中为 ReflectionClass。
     * @param string $type 需要读取的属性注解完整类名。
     * @return mixed 注解实例或 null。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $table = ModuleRegistry::attribute(new \ReflectionClass($class), \Database\Annotation\Table::class);
     */
    public static function attribute(\ReflectionClass $class, string $type): mixed
    {
        return ModuleDiscovery::attribute($class, $type);
    }
    /**
     * 委托发现器加入模块及其依赖，不立即实例化服务。
     *
     * @param string $class 待发现的模块或组件完整类名；attribute 中为 ReflectionClass。
     * @return void 无返回值。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $modules->add(\App\AppModule::class);
     */
    public function add(string $class): void
    {
        $this->discovery->add($class);
    }
    /**
     * 委托发现器验证并登记单个组件类。
     *
     * @param string $class 待发现的模块或组件完整类名；attribute 中为 ReflectionClass。
     * @return void 无返回值。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $modules->register($componentClass);
     */
    public function register(string $class): void
    {
        $this->discovery->register($class);
    }
    /**
     * 先执行 config/services.php 回调，再补齐注解默认服务；已经显式注册的标识保持不变。
     *
     * @param Container $container 目标容器；job 中可为 null，表示由注册器创建并负责清理。
     * @return void 无返回值，修改目标容器。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $modules->services($services);
     */
    public function services(Container $container): void
    {
        // 显式工厂用于覆盖，注解提供默认注册。
        $configure = $this->config->get('services');
        if ($configure !== null) {
            $container->configureOnce('config/services.php', $configure);
        }
        foreach ($this->discovery->services() as $id => $class) {
            $lifetime = $this->discovery->serviceLifetime($id);
            $container->configureOnce('module.service:' . $id, static function (Container $services) use ($id, $class, $lifetime): void {
                if (!$services->has($id)) {
                    $services->$lifetime($id, $class);
                }
            });
        }
    }

    /**
     * 派生新服务作用域并安装模块服务，失败时关闭新作用域。
     *
     * 参数：无。
     *
     * @return Container 独立 Container，所属 ServiceScope 可从容器取得并负责关闭。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $childServices = $modules->container();
     */
    public function container(): Container
    {
        $scope = $this->scope->fork();
        $container = $scope->services();
        try {
            $this->services($container);
            return $container;
        } catch (\Throwable $error) {
            $scope->close();
            throw $error;
        }
    }

    /**
     * 将发现到的普通及 WebSocket 路由安装到目标应用或路由器。
     *
     * @param Application|Router $app 待安装路由的 Application 或 Router。
     * @return void 无返回值。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $modules->routes($app->router());
     */
    public function routes(Application|Router $app): void
    {
        $services = $app instanceof Application ? $app->services() : $this->scope->services();
        foreach ($this->discovery->routes() as $route) {
            if ($route['websocket']) {
                $app->websocket($route['path'], $route['action'], $route['name'], $route['middleware']);
            } else {
                $app->route($route['method'], $route['path'], $route['action'], $route['name'], $route['middleware']);
            }
        }
        if ($services->has(\Loom\Http\RouteProvider::class)) {
            $provider = $services->get(\Loom\Http\RouteProvider::class);
            if (!($provider instanceof \Loom\Http\RouteProvider)) {
                throw new \LogicException('HTTP route provider must implement RouteProvider');
            }
            $provider->register($app instanceof Application ? $app->router() : $app);
        }
    }

    /**
     * 列出代码声明的定时器及周期，供运维界面展示，不查询实时执行状态。
     *
     * 参数：无。
     *
     * @return array 包含 class、seconds、state 的列表。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $definitions = $modules->timerDefinitions();
     */
    public function timerDefinitions(): array
    {
        $out = [];
        foreach ($this->discovery->timers() as $class) {
            $timer = self::attribute(new \ReflectionClass($class), \Loom\Timer::class);
            $out[] = ['class' => $class, 'seconds' => $timer->seconds, 'state' => '代码声明；独立运行状态未采集'];
        }
        return $out;
    }

    /**
     * 为每个定时器创建独立模块容器并注册到运行器，防止共享数据库事务。
     *
     * @param Container $container 目标容器；job 中可为 null，表示由注册器创建并负责清理。
     * @return TimerRunner 已注册但未启动的 TimerRunner。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $runner = $modules->timers($services); $runner->start();
     */
    public function timers(Container $container): TimerRunner
    {
        $runner = new TimerRunner($container);
        foreach ($this->discovery->timers() as $class) {
            // 并发定时器绝不能共享启动时的数据库连接或彼此的事务。
            $services = $this->container();
            try {
                $runner->register($class, $services);
            } catch (\Throwable $error) {
                $services->get(\Loom\ServiceScope::class)->close();
                throw $error;
            }
        }
        return $runner;
    }

    /**
     * 把发现的命令类实例注册到控制台。
     *
     * @param \Loom\Console\Console $console 待安装命令的控制台实例。
     * @return void 无返回值。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $modules->commands($console);
     */
    public function commands(\Loom\Console\Console $console): void
    {
        foreach ($this->discovery->commands() as $class) {
            $console->add($class);
        }
    }

    /**
     * 按任务种类解析处理器并临时绑定请求；没有外部容器时自行创建及关闭容器。
     *
     * @param string $kind 已通过 Job 注解登记的任务种类。
     * @param Request $request 当前 HTTP 请求；中间件修改属性时应传递请求副本。
     * @param ?Container $container 目标容器；job 中可为 null，表示由注册器创建并负责清理。默认值为 null。
     * @return Response 任务处理方法返回的 Response。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $response = $modules->job('report.build', $request, $services);
     */
    public function job(string $kind, Request $request, ?Container $container = null): Response
    {
        $jobs = $this->discovery->jobs();
        if (!isset($jobs[$kind])) {
            throw new \LogicException('Unknown job kind: ' . $kind);
        }
        $target = $jobs[$kind];
        $owned = $container === null;
        $container = $container ?? $this->container();
        $context = $container->get(\Loom\RequestContext::class);
        $previous = $context->replace($request);
        try {
            $handler = $container->get($target[0]);
            $method = $target[1];
            return $handler->$method($request);
        } finally {
            $context->bind($previous);
            if ($owned) {
                $container->get(\Loom\ServiceScope::class)->close();
            }
        }
    }
}
