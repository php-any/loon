<?php

declare(strict_types=1);

namespace Loom;

use Loom\Attributes\Module;
use Loom\Attributes\Controller;
use Loom\Attributes\Route;
use Loom\Attributes\Service;
use Loom\Attributes\Job;
use Database\Annotation\Table;

// 发现并验证声明，不构造应用服务。
/**
 * 发现并验证模块中的服务、模型、控制器、任务、定时器和命令声明，保存元数据而不实例化业务服务。
 *
 * 构造参数：
 * - $config（Config）：项目配置实例，提供配置读取及项目路径解析。
 *
 * 返回值：使用 new 构造时得到 ModuleDiscovery 实例；注册到容器时由容器管理实例的创建与复用。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $discovery = (new ModuleDiscovery($config))->configured();
 */
final class ModuleDiscovery
{
    private Config $config;
    /**
     * 初始化 ModuleDiscovery 实例及其依赖。发现并验证模块中的服务、模型、控制器、任务、定时器和命令声明，保存元数据而不实例化业务服务。
     *
     * @param Config $config 项目配置实例，提供配置读取及项目路径解析。
     * @return void 无直接返回值；通过 new 表达式取得初始化后的实例。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $discovery = (new ModuleDiscovery($config))->configured();
     */
    public function __construct(Config $config)
    {
        $this->config = $config;
        $this->gate = new \Container\Container();
    }
    private array $modules = [];
    private array $visiting = [];
    private array $classes = [];
    private array $services = [];
    private array $serviceLifetimes = [];
    private array $routes = [];
    private array $jobs = [];
    private array $timers = [];
    private array $commands = [];
    private bool $frozen = false;
    private ?self $snapshot = null;
    private \Container\Container $gate;
    private ?Router $routeTable = null;

    /**
     * 首次按配置发现模块并冻结元数据；已冻结的应用级发现结果直接复用。
     *
     * 参数：无。
     *
     * @return self 已装载配置模块的独立发现器。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $discovery = $discovery->configured();
     */
    public function configured(): self
    {
        if ($this->frozen) {
            return $this;
        }
        return \Loom\Native\withSingletonLock($this->gate, function (): self {
            if ($this->snapshot === null) {
                $registry = new self($this->config);
                foreach ($this->config->get('modules', []) as $module) {
                    $registry->add($module);
                }
                $registry->routeTable = $registry->router();
                $registry->frozen = true;
                $this->snapshot = $registry;
            }
            return $this->snapshot;
        });
    }

    /**
     * 读取类上指定类型的唯一属性注解，重复声明时抛出 LogicException。
     *
     * @param \ReflectionClass $class 待检查的类名；attribute、handler、route 中为该类的 ReflectionClass。
     * @param string $type 要读取的属性注解完整类名。
     * @return mixed 注解实例；不存在时为 null。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $module = ModuleDiscovery::attribute(new \ReflectionClass($class), \Loom\Attributes\Module::class);
     */
    public static function attribute(\ReflectionClass $class, string $type): mixed
    {
        $attributes = $class->getAttributes($type);
        if (count($attributes) > 1) {
            throw new \LogicException('Duplicate ' . $type . ' on ' . $class->getName());
        }
        return $attributes === [] ? null : $attributes[0]->newInstance();
    }

    /**
     * 递归加入模块，先处理 imports 再扫描本模块；重复模块跳过，循环依赖报错。
     *
     * @param string $class 待检查的类名；attribute、handler、route 中为该类的 ReflectionClass。
     * @return void 无返回值，更新发现器内部元数据。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $discovery->add(\App\AppModule::class);
     */
    public function add(string $class): void
    {
        if (isset($this->modules[$class])) {
            return;
        }
        if ($this->frozen) {
            throw new \LogicException('Configured module metadata is immutable');
        }
        if (isset($this->visiting[$class])) {
            throw new \LogicException('Module import cycle: ' . $class);
        }
        $module = self::attribute(new \ReflectionClass($class), \Loom\Attributes\Module::class);
        if ($module === null) {
            throw new \LogicException('Missing Module annotation: ' . $class);
        }
        $this->visiting[$class] = true;
        try {
            foreach ($module->imports as $import) {
                $this->add($import);
            }
            foreach ($module->scan as $namespace => $directory) {
                foreach (ClassDiscovery::scan($this->config->path($directory), $namespace) as $component) {
                    $this->register($component);
                }
            }
            $this->modules[$class] = true;
        } finally {
            unset($this->visiting[$class]);
        }
    }

    /**
     * 检查一个类上的服务、模型、控制器、任务、定时器及命令声明，并登记合法定义。
     *
     * @param string $class 待检查的类名；attribute、handler、route 中为该类的 ReflectionClass。
     * @return void 无返回值；重复服务标识或无效类结构抛出 LogicException。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $discovery->register($componentClass);
     */
    public function register(string $class): void
    {
        if (isset($this->classes[$class])) {
            return;
        }
        if ($this->frozen) {
            throw new \LogicException('Configured module metadata is immutable');
        }
        $reflection = new \ReflectionClass($class);
        $service = self::attribute($reflection, \Loom\Attributes\Service::class);
        if ($service !== null) {
            if (!$reflection->isInstantiable()) {
                throw new \LogicException('Service must be concrete: ' . $class);
            }
            $id = $service->id === '' ? $class : $service->id;
            if (isset($this->services[$id])) {
                throw new \LogicException('Duplicate service id: ' . $id);
            }
            $this->services[$id] = $class;
            $this->serviceLifetimes[$id] = $service->lifetime;
        }
        $table = self::attribute($reflection, \Database\Annotation\Table::class);
        if ($table !== null) {
            if ($reflection->isSubclassOf(\Loom\Model::class)) {
                $class::validateDefinition();
            } else {
                throw new \LogicException('Application models must extend Loom\\Model: ' . $class);
            }
        }
        $controller = self::attribute($reflection, \Loom\Attributes\Controller::class);
        $classRoutes = $reflection->getAttributes(\Loom\Attributes\Route::class);
        if ($classRoutes !== [] && $controller === null) {
            throw new \LogicException('Route requires Controller: ' . $class);
        }
        foreach ($classRoutes as $attribute) {
            $route = $attribute->newInstance();
            $this->route($reflection, $controller, $route, $route->handler);
        }
        foreach ($reflection->getMethods() as $method) {
            foreach ($method->getAttributes(\Loom\Attributes\Route::class) as $attribute) {
                if ($controller === null) {
                    throw new \LogicException('Route requires Controller: ' . $class);
                }
                $this->route($reflection, $controller, $attribute->newInstance(), $method->getName());
            }
        }
        $job = self::attribute($reflection, \Loom\Attributes\Job::class);
        if ($job !== null) {
            if ($job->kind === '' || isset($this->jobs[$job->kind])) {
                throw new \LogicException('Empty or duplicate job kind: ' . $job->kind);
            }
            $this->handler($reflection, $job->method);
            $this->jobs[$job->kind] = [$class, $job->method];
        }
        if (self::attribute($reflection, \Loom\Timer::class) !== null) {
            $this->timers[] = $class;
        }
        if (self::attribute($reflection, \Loom\Attributes\ConsoleCommand::class) !== null) {
            if (!$reflection->isInstantiable() || !$reflection->isSubclassOf(\Loom\Console\Command::class)) {
                throw new \LogicException('ConsoleCommand requires a concrete Command subclass: ' . $class);
            }
            $this->commands[] = $class;
        }
        $this->classes[$class] = true;
    }

    /**
     * 验证目标类可实例化且处理方法为公开、非静态的实例方法。
     *
     * @param \ReflectionClass $class 待检查的类名；attribute、handler、route 中为该类的 ReflectionClass。
     * @param string $method 待验证或注册的处理方法名称。
     * @return void 无返回值；不符合约定时抛出 LogicException。
     *
     * 内部调用示例（仅类内使用）：
     * @example $this->handler(new \ReflectionClass($class), 'handle');
     */
    private function handler(\ReflectionClass $class, string $method): void
    {
        if (!$class->isInstantiable() || !$class->hasMethod($method)) {
            throw new \LogicException('Invalid handler: ' . $class->getName() . '::' . $method);
        }
        $reflection = $class->getMethod($method);
        if (!$reflection->isPublic() || $reflection->isStatic()) {
            throw new \LogicException('Handler must be a public instance method: ' . $class->getName() . '::' . $method);
        }
    }

    /**
     * 合并控制器和路由注解，校验处理方法及 WebSocket 方法后保存路由定义。
     *
     * @param \ReflectionClass $class 待检查的类名；attribute、handler、route 中为该类的 ReflectionClass。
     * @param Controller $controller 控制器注解实例，提供前缀和公共中间件。
     * @param Route $route 路由注解实例，提供方法、路径、名称等元数据。
     * @param string $method 待验证或注册的处理方法名称。
     * @return void 无返回值。
     *
     * 内部调用示例（仅类内使用）：
     * @example $this->route($reflection, $controller, $route, 'show');
     */
    private function route(\ReflectionClass $class, Controller $controller, Route $route, string $method): void
    {
        $this->handler($class, $method);
        if ($route->websocket && strtoupper($route->method) !== 'GET') {
            throw new \LogicException('WebSocket route requires GET');
        }
        $middleware = array_merge($controller->middleware, $route->middleware);
        foreach ($middleware as $entry) {
            if (!is_string($entry) || $entry === '') {
                throw new \LogicException('Attribute middleware must use service class names; register instances through a RouteProvider');
            }
        }
        $this->routes[] = ['method' => $route->method, 'path' => rtrim($controller->prefix, '/') . $route->path,
            'action' => [$class->getName(), $method], 'name' => $route->name,
            'middleware' => $middleware, 'websocket' => $route->websocket];
    }

    /** 返回指定模块服务声明的生命周期。 */
    public function serviceLifetime(string $id): string
    {
        return $this->serviceLifetimes[$id];
    }

    /**
     * 取得已发现的服务标识与类名映射。
     *
     * 参数：无。
     *
     * @return array 服务标识到完整类名的关联数组。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $definitions = $discovery->services();
     */
    public function services(): array
    {
        return $this->services;
    }
    /**
     * 取得合并完成的路由元数据列表。
     *
     * 参数：无。
     *
     * @return array 每项含 method、path、action、name、middleware、websocket 的数组。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $routes = $discovery->routes();
     */
    public function routes(): array
    {
        return $this->routes;
    }

    /** 已配置的声明路由只校验一次；返回独立路由表供作用域内追加显式路由。 */
    public function router(): Router
    {
        if ($this->routeTable !== null) {
            return clone $this->routeTable;
        }
        $router = new Router();
        foreach ($this->routes as $route) {
            if ($route['websocket']) {
                $router->websocket($route['path'], $route['action'], $route['name'], $route['middleware']);
            } else {
                $router->route($route['method'], $route['path'], $route['action'], $route['name'], $route['middleware']);
            }
        }
        return $router;
    }
    /**
     * 取得任务种类到处理目标的映射。
     *
     * 参数：无。
     *
     * @return array kind 到 [类名, 方法名] 的关联数组。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $jobs = $discovery->jobs();
     */
    public function jobs(): array
    {
        return $this->jobs;
    }
    /**
     * 取得已发现的定时器类列表，尚未实例化或启动。
     *
     * 参数：无。
     *
     * @return array 定时器完整类名列表。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $classes = $discovery->timers();
     */
    public function timers(): array
    {
        return $this->timers;
    }
    /**
     * 取得已验证的控制台命令类列表。
     *
     * 参数：无。
     *
     * @return array ConsoleCommand 类名列表。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $classes = $discovery->commands();
     */
    public function commands(): array
    {
        return $this->commands;
    }
}
