<?php

declare(strict_types=1);

namespace Loom;

// 运行时安装使用元数据；发现阶段绝不实例化应用服务。
/**
 * 把发现器里的元数据装进容器、路由器、控制台和定时器。安装服务时才解析类名，构造本对象时不创建业务服务。
 *
 * 构造参数：
 * - $discovery（ModuleDiscovery）：提供服务、路由、任务、定时器和命令元数据。
 * - $config（Config）：services 安装时读取 services 配置回调。
 * - $scope（ServiceScope）：container 和定时器登记时用来 fork 隔离作用域。
 *
 * 返回值：使用 new 构造时得到尚未向任何容器安装服务的 ModuleRegistry。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $modules = new ModuleRegistry($discovery, $config, $scope);
 */
final class ModuleRegistry
{
    private ModuleDiscovery $discovery;
    private Config $config;
    private ServiceScope $scope;
    /**
     * 保存发现器、配置和作用域。构造时不扫描模块，也不向容器登记服务。
     *
     * @param ModuleDiscovery $discovery 读取元数据时使用的发现器。
     * @param Config $config 安装服务时用来取 services 配置回调。
     * @param ServiceScope $scope 派生隔离容器时使用的父作用域。
     * @return void 无直接返回值。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $modules = new ModuleRegistry($discovery, $config, $scope);
     */
    public function __construct(ModuleDiscovery $discovery, Config $config, ServiceScope $scope)
    {
        $this->discovery = $discovery;
        $this->config = $config;
        $this->scope = $scope;
    }
    /**
     * 用发现器的 configured 结果、当前配置和当前作用域构造一个新注册器。当前对象保持原样。
     *
     * 参数：无。
     *
     * @return self 绑定到已冻结发现结果的新 ModuleRegistry。发现失败时异常来自发现器。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $loaded = $modules->configured();
     */
    public function configured(): self
    {
        return new self($this->discovery->configured(), $this->config, $this->scope);
    }
    /**
     * 转调 ModuleDiscovery::attribute，读取类上这一种注解的唯一实例。
     *
     * @param \ReflectionClass $class 要读取注解的类反射。
     * @param string $type 注解的完整类名。
     * @return mixed 注解实例；没有这种注解时为 null。重复注解时异常来自发现器。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $table = ModuleRegistry::attribute(new \ReflectionClass($class), \Database\Annotation\Table::class);
     */
    public static function attribute(\ReflectionClass $class, string $type): mixed
    {
        return ModuleDiscovery::attribute($class, $type);
    }
    /**
     * 把模块类交给发现器递归导入和扫描。本方法不创建服务实例。
     *
     * @param string $class 带 Module 注解的模块类名。
     * @return void 无返回值。发现器拒绝该模块时异常原样抛出。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $modules->add(\App\AppModule::class);
     */
    public function add(string $class): void
    {
        $this->discovery->add($class);
    }
    /**
     * 把一个组件类交给发现器做声明校验和登记。本方法不把结果写入容器。
     *
     * @param string $class 要登记的组件完整类名。
     * @return void 无返回值。声明不合法时异常来自发现器。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $modules->register(\App\Http\ItemController::class);
     */
    public function register(string $class): void
    {
        $this->discovery->register($class);
    }
    /**
     * 配置里的 services 不是 null 时，以 config/services.php 为键交给容器 configureOnce。
     * 然后为每个已发现服务再登记一次：容器里还没有该标识时，按发现到的生命周期注册类名；已有标识保持不动。
     *
     * @param Container $container 接受这些登记的容器。
     * @return void 无返回值。配置回调失败时异常抛出，已经写进容器的登记会留下。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $modules->services($container);
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
     * 从当前作用域 fork 出新作用域，在它的容器上调用 services。
     * 安装过程抛错时先关闭这个新作用域，再把原异常抛出去。
     *
     * 参数：无。
     *
     * @return Container 已安装模块服务的子容器。调用方以后通过该容器上的 ServiceScope 关闭它。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $child = $modules->container();
     */
    public function container(): Container
    {
        $scope = $this->scope->fork();
        $container = $scope->container();
        try {
            $this->services($container);
            return $container;
        } catch (\Throwable $error) {
            $scope->close();
            throw $error;
        }
    }

    /**
     * 把发现到的路由逐条登记到目标上：websocket 为真时调用 websocket，否则调用 route。
     * 目标是 Application 时从应用取容器和路由器；目标是 Router 时用本注册器的作用域容器，并把路由登记到这个路由器。最后都交给 providerRoutes，仅在该容器注册了 HTTP RouteProvider 时才追加路由。
     *
     * @param Application|Router $app 接受路由登记的应用或路由器。
     * @return void 无返回值。RouteProvider 的类型不符时抛出 LogicException。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $modules->routes($app);
     */
    public function routes(Application|Router $app): void
    {
        $services = $app instanceof Application ? $app->container() : $this->scope->container();
        foreach ($this->discovery->routes() as $route) {
            if ($route['websocket']) {
                $app->websocket($route['path'], $route['action'], $route['name'], $route['middleware']);
            } else {
                $app->route($route['method'], $route['path'], $route['action'], $route['name'], $route['middleware']);
            }
        }
        $this->providerRoutes($services, $app instanceof Application ? $app->router() : $app);
    }

    /**
     * 用已经校验过的声明路由创建 Application，再从传入容器解析 RouteProvider 并追加路由。
     * 不重新扫描模块。provider 未绑定时只保留声明路由。
     *
     * @param Container $services 提供 RouteProvider 和请求期服务的容器。
     * @return Application 装好声明路由和 provider 路由的应用。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $app = $modules->application($services);
     */
    public function application(Container $services): Application
    {
        $app = new Application($services, $this->discovery->router());
        $this->providerRoutes($services, $app->router());
        return $app;
    }

    /**
     * 当前容器注册了 HTTP RouteProvider 时，调用它向路由器追加路由。
     * 未注册则什么也不做。解析结果必须实现 RouteProvider。
     *
     * @param Container $services 解析 provider 的容器，通常是本次应用或请求容器。
     * @param Router $router 接收追加路由的路由器。
     * @return void 无返回值。类型不符时抛出 LogicException。
     *
     * 内部调用示例（仅类内使用）：
     * @example $this->providerRoutes($services, $app->router());
     */
    private function providerRoutes(Container $services, Router $router): void
    {
        if ($services->has(\Loom\Http\RouteProvider::class)) {
            $provider = $services->get(\Loom\Http\RouteProvider::class);
            if (!($provider instanceof \Loom\Http\RouteProvider)) {
                throw new \LogicException('HTTP route provider must implement RouteProvider');
            }
            $provider->register($router);
        }
    }

    /**
     * 对发现到的每个定时器类读取 Timer 注解，列出类名和秒数。
     * state 固定写成代码已声明、运行状态尚未采集。本方法不查询定时器是否正在运行。
     *
     * 参数：无。
     *
     * @return array 每项含 class、seconds、state。没有定时器时为空数组。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $rows = $modules->timerDefinitions();
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
     * 用传入容器创建一个尚未启动的 TimerRunner。每个定时器类再通过 container 得到自己的子容器，并 register 到运行器。
     * 某个定时器注册抛错时，关闭该子容器上的 ServiceScope，再抛出原异常。此前已经登记成功的子容器留在运行器里。
     *
     * @param Container $container 运行器在单次 register 未另给容器时使用的默认容器。本方法为每个定时器另行传入独立子容器。
     * @return TimerRunner 定时器已登记、尚未 start 的运行器。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $runner = $modules->timers($container);
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
     * 把发现到的每个命令类名交给控制台 add。命令对象由控制台以后再创建。
     *
     * @param \Loom\Console\Console $console 接受命令类名的控制台。
     * @return void 无返回值。控制台拒绝某个类时异常抛出，已经加入的命令会留下。
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
     * 按 kind 找到 [类名, 方法名]。未知 kind 时抛出 LogicException，并且不会创建容器。
     * container 为 null 时先调用 container 得到子容器，并在结束时关闭它；传入容器时由调用方负责关闭。
     * 调用处理方法前用 RequestContext 换上这份请求，finally 里恢复先前的绑定。处理方法的返回值直接返回。
     *
     * @param string $kind 发现阶段登记过的任务种类。
     * @param Request $request 传给处理方法的请求。
     * @param ?Container $container 解析处理器的容器。默认 null，表示由本方法创建并在返回前关闭。
     * @return Response 处理方法返回的响应。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $response = $modules->job('report.build', $request);
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
            $scope = $container->get(\Loom\ServiceScope::class);
            return \Loom\ModelContext::during($scope, static function () use ($handler, $method, $request): Response {
                return $handler->$method($request);
            });
        } finally {
            $context->bind($previous);
            if ($owned) {
                $container->get(\Loom\ServiceScope::class)->close();
            }
        }
    }
}
