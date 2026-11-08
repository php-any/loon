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
 * 扫描带注解的模块，校验服务、模型、控制器、任务、定时器和命令，只把元数据留在本对象里。
 *
 * 构造参数：
 * - $config（Config）：解析扫描目录；configured 时还用来读取 modules。
 *
 * 返回值：使用 new 构造时得到尚未扫描的 ModuleDiscovery。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $discovery = new ModuleDiscovery($config);
 */
final class ModuleDiscovery
{
    private Config $config;
    /**
     * 保存配置。构造结束时还没有扫描任何模块。
     *
     * @param Config $config 解析模块目录，以及读取 modules 配置时使用的配置对象。
     * @return void 无直接返回值。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $discovery = new ModuleDiscovery($config);
     */
    public function __construct(Config $config)
    {
        $this->config = $config;
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
    private ?Router $routeTable = null;

    /**
     * 按 modules 配置逐个 add，生成路由表，再冻结。ApplicationBuilder::build() 调用它一次，结果作为单例登记。
     * 冻结后 add 和 register 抛出 LogicException。add 或生成路由表失败时异常原样抛出。
     *
     * @param Config $config 项目配置。
     * @return self 已装载配置模块并冻结的发现器。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $discovery = ModuleDiscovery::configured($config);
     */
    public static function configured(Config $config): self
    {
        $discovery = new self($config);
        foreach ($config->get('modules', []) as $module) {
            $discovery->add($module);
        }
        $discovery->routeTable = $discovery->router();
        $discovery->frozen = true;
        return $discovery;
    }

    /**
     * 列出发现到的定时器类和周期，供运维页面展示。state 固定写成代码已声明、运行状态尚未采集。
     *
     * 参数：无。
     *
     * @return array 每项含 class、seconds、state。没有定时器时为空数组。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $rows = $discovery->timerDefinitions();
     */
    public function timerDefinitions(): array
    {
        $out = [];
        foreach ($this->timers as $class) {
            $timer = self::attribute(new \ReflectionClass($class), \Loom\Timer::class);
            $out[] = ['class' => $class, 'seconds' => $timer->seconds, 'state' => '代码声明；独立运行状态未采集'];
        }
        return $out;
    }

    /**
     * 读取类上某一种注解的唯一实例。没有这种注解时返回 null；多于一个时抛出 LogicException。
     *
     * @param \ReflectionClass $class 要读取注解的类反射。
     * @param string $type 注解的完整类名。
     * @return mixed 注解实例；该类没有这种注解时为 null。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $module = ModuleDiscovery::attribute(new \ReflectionClass(\App\AppModule::class), \Loom\Attributes\Module::class);
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
     * 登记一个带 Module 注解的类。同一个类已经登记过则直接返回。
     * 对象已冻结、缺少 Module 注解，或导入链又回到正在处理的类时，抛出 LogicException。
     * 否则先递归 add 每个 imports，再按 scan 扫描目录并把每个类交给 register，最后记入已完成模块。
     *
     * @param string $class 带 Module 注解的模块类名。
     * @return void 无返回值。
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
     * 检查一个组件类并记下合法声明。同一个类只处理一次；对象已冻结时抛出 LogicException。
     * 有 Service 时类必须可实例化，标识为空则改用类名，标识重复则失败。
     * 有 Table 时类必须是 Model 子类，并调用它的 validateDefinition。
     * 类或方法上有 Route 但没有 Controller 时失败。Job 的 kind 不能为空或重复，处理方法交给 handler 校验。
     * 带 Timer 的类只记录类名。带 ConsoleCommand 的类必须是可实例化的 Command 子类。
     *
     * @param string $class 要检查的组件完整类名。
     * @return void 无返回值。声明不合法时抛出 LogicException。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $discovery->register(\App\Http\ItemController::class);
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
     * 确认类可以实例化，并且指定方法存在、公开、不是静态方法。任一条件不满足都抛出 LogicException。
     *
     * @param \ReflectionClass $class 声明处理方法的类。
     * @param string $method 要校验的方法名。
     * @return void 无返回值。
     *
     * 内部调用示例（仅类内使用）：
     * @example $this->handler($class, $method);
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
     * 先用 handler 校验处理方法。WebSocket 路由的方法不是 GET 时抛出 LogicException。
     * 类级和本路由的中间件合并后，每一项都必须是非空字符串，否则抛出 LogicException。
     * 通过后把控制器前缀和路由路径拼在一起，连同动作、名称、中间件和 websocket 标记追加到路由列表。
     *
     * @param \ReflectionClass $class 控制器类反射，用来取类名并校验方法。
     * @param Controller $controller 提供前缀和类级中间件的控制器注解。
     * @param Route $route 这一条路由的方法、路径、名称、中间件和 websocket 标记。
     * @param string $method 实际调用的控制器方法名。
     * @return void 无返回值。
     *
     * 内部调用示例（仅类内使用）：
     * @example $this->route($class, $controller, $route, $method);
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
                throw new \LogicException('Attribute middleware must use service class names; register instances from a service provider');
            }
        }
        $this->routes[] = ['method' => $route->method, 'path' => rtrim($controller->prefix, '/') . $route->path,
            'action' => [$class->getName(), $method], 'name' => $route->name,
            'middleware' => $middleware, 'websocket' => $route->websocket];
    }

    /**
     * 返回模块服务声明的生命周期。标识必须已经在发现结果里。
     *
     * @param string $id 服务标识，空标识在发现时已换成类名。
     * @return string singleton、scoped 或 transient。未知标识会触发未定义索引。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $lifetime = $discovery->serviceLifetime(Clock::class);
     */
    public function serviceLifetime(string $id): string
    {
        return $this->serviceLifetimes[$id];
    }

    /**
     * 返回 register 记下的服务标识到实现类名。这些类在这里不会被实例化。
     *
     * 参数：无。
     *
     * @return array 标识到完整类名。还没有服务时为空数组。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $map = $discovery->services();
     */
    public function services(): array
    {
        return $this->services;
    }
    /**
     * 返回已经拼好前缀的路由声明。每一项含 method、path、action、name、middleware、websocket。
     * 这里不创建 Router。
     *
     * 参数：无。
     *
     * @return array 路由元数据列表。还没有路由时为空数组。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $routes = $discovery->routes();
     */
    public function routes(): array
    {
        return $this->routes;
    }

    /**
     * 把已发现的声明路由装进一个新的 Router。已有缓存表时返回它的副本，避免调用方追加路由时改到共享表。
     * 这里不重新扫描目录，也不执行应用留下的路由回调。
     *
     * 参数：无。
     *
     * @return Router 只含声明路由的新路由器或缓存表副本。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $router = $discovery->router();
     */
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
     * 返回任务 kind 到处理目标的映射。值是登记时保存的 [类名, 方法名]，类尚未实例化。
     *
     * 参数：无。
     *
     * @return array kind 到 [类名, 方法名]。没有任务时为空数组。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $jobs = $discovery->jobs();
     */
    public function jobs(): array
    {
        return $this->jobs;
    }
    /**
     * 返回带 Timer 注解的类名，顺序与 register 发现它们的顺序一致。不创建定时器，也不启动。
     *
     * 参数：无。
     *
     * @return array 定时器类名列表。没有定时器时为空数组。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $timers = $discovery->timers();
     */
    public function timers(): array
    {
        return $this->timers;
    }
    /**
     * 返回通过 ConsoleCommand 校验的命令类名，顺序与发现顺序一致。不实例化命令。
     *
     * 参数：无。
     *
     * @return array Command 子类名列表。没有命令时为空数组。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $commands = $discovery->commands();
     */
    public function commands(): array
    {
        return $this->commands;
    }
}
