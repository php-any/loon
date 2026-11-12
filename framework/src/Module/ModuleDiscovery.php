<?php

declare(strict_types=1);

namespace Loom;

use Loom\Attributes\Module;
use Loom\Attributes\HttpRoute;
use Loom\Attributes\Job;
use Database\Annotation\Table;

// 发现并验证声明，不构造应用服务。
/**
 * 扫描带注解的模块，校验服务、模型、控制器、任务、定时器和命令，只把元数据留在本对象里。
 *
 * 构造参数：
 * - $config（Config）：解析扫描目录。
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
     * @param Config $config 解析模块目录时使用的配置对象。
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
    /** @var array<string, list<array{0: string, 1: string}>> */
    private array $eventListeners = [];
    private bool $frozen = false;
    private ?Router $routeTable = null;

    /** @var array<string, string> */
    private const HTTP_MAPPINGS = [
        \Net\Annotation\GetMapping::class => 'GET',
        \Net\Annotation\PostMapping::class => 'POST',
        \Net\Annotation\PutMapping::class => 'PUT',
        \Net\Annotation\DeleteMapping::class => 'DELETE',
    ];

    /**
     * 按给定模块类名逐个 add，生成路由表，再冻结。ApplicationBuilder::build() 调用它一次，结果作为单例登记。
     * 冻结后 add 和 register 抛出 LogicException。add 或生成路由表失败时异常原样抛出。
     *
     * @param Config $config 项目配置。
     * @param list<string> $modules 启动模块完整类名；空列表表示不发现应用模块。
     * @return self 已装载模块并冻结的发现器。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $discovery = ModuleDiscovery::configured($config, [\App\AppModule::class]);
     */
    public static function configured(Config $config, array $modules = []): self
    {
        $discovery = new self($config);
        foreach ($modules as $module) {
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
     * @return array 每项含 class、seconds、expression、timezone、schedule、state。没有定时器时为空数组。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $rows = $discovery->timerDefinitions();
     */
    public function timerDefinitions(): array
    {
        $out = [];
        foreach ($this->timers as $class) {
            $timer = self::attribute(new \ReflectionClass($class), \Loom\Timer::class);
            $out[] = [
                'class' => $class,
                'seconds' => $timer->isInterval() ? $timer->seconds : null,
                'expression' => $timer->isCron() ? $timer->cron : null,
                'timezone' => $timer->isCron() ? $timer->timezone : null,
                'schedule' => $timer->scheduleLabel(),
                'state' => '代码声明；独立运行状态未采集',
            ];
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
     * @return array{0: string, 1: string}|null [id, lifetime] 或尚无生命周期声明时 null
     */
    private function serviceDeclaration(\ReflectionClass $reflection): ?array
    {
        $singleton = self::attribute($reflection, \Container\Annotation\Singleton::class);
        $scoped = self::attribute($reflection, \Container\Annotation\Scoped::class);
        $component = self::attribute($reflection, \Container\Annotation\Component::class);
        $markers = array_values(array_filter([$singleton, $scoped, $component], static fn ($marker): bool => $marker !== null));
        if (count($markers) > 1) {
            throw new \LogicException('Conflicting service lifetime annotations on ' . $reflection->getName());
        }
        $bind = self::attribute($reflection, \Container\Annotation\Bind::class);
        $lifetimeMarker = $markers[0] ?? null;
        if ($lifetimeMarker === null && $bind === null) {
            return null;
        }
        if ($lifetimeMarker instanceof \Container\Annotation\Singleton) {
            $lifetime = 'singleton';
            $name = $lifetimeMarker->name ?? '';
        } elseif ($lifetimeMarker instanceof \Container\Annotation\Scoped) {
            $lifetime = 'scoped';
            $name = $lifetimeMarker->name ?? '';
        } elseif ($lifetimeMarker instanceof \Container\Annotation\Component) {
            $lifetime = 'transient';
            $name = $lifetimeMarker->name ?? '';
        } else {
            $lifetime = 'scoped';
            $name = '';
        }
        if ($bind !== null) {
            $abstract = $bind->abstract ?? '';
            if ($abstract === '') {
                throw new \LogicException('Bind requires a non-empty abstract on ' . $reflection->getName());
            }
            return [$abstract, $lifetime];
        }
        $id = $name === '' || $name === null ? $reflection->getName() : (string) $name;
        return [$id, $lifetime];
    }

    /**
     * @return list<string>
     */
    private function classMiddleware(\ReflectionClass $reflection): array
    {
        $middleware = [];
        foreach ($reflection->getAttributes(\Net\Annotation\Middleware::class) as $attribute) {
            $instance = $attribute->newInstance();
            $className = $instance->className ?? '';
            if (!is_string($className) || $className === '') {
                throw new \LogicException('Middleware requires a non-empty class name on ' . $reflection->getName());
            }
            $middleware[] = $className;
        }
        return $middleware;
    }

    /**
     * 检查一个组件类并记下合法声明。同一个类只处理一次；对象已冻结时抛出 LogicException。
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
        $service = $this->serviceDeclaration($reflection);
        if ($service !== null) {
            if (!$reflection->isInstantiable()) {
                throw new \LogicException('Service must be concrete: ' . $class);
            }
            [$id, $lifetime] = $service;
            if (isset($this->services[$id])) {
                throw new \LogicException('Duplicate service id: ' . $id);
            }
            $this->services[$id] = $class;
            $this->serviceLifetimes[$id] = $lifetime;
        }
        $table = self::attribute($reflection, \Database\Annotation\Table::class);
        if ($table !== null) {
            if ($reflection->isSubclassOf(\Loom\Model::class)) {
                $class::validateDefinition();
            } else {
                throw new \LogicException('Application models must extend Loom\\Model: ' . $class);
            }
        }
        $controller = self::attribute($reflection, \Net\Annotation\Controller::class);
        $classRoute = self::attribute($reflection, \Net\Annotation\Route::class);
        $classMiddleware = $this->classMiddleware($reflection);
        if ($controller === null && ($classRoute !== null || $classMiddleware !== [])) {
            throw new \LogicException('Route metadata requires Controller: ' . $class);
        }
        $prefix = '';
        if ($classRoute !== null) {
            $routePrefix = $classRoute->prefix ?? '/';
            $prefix = $routePrefix === '/' ? '' : (string) $routePrefix;
        }
        foreach ($reflection->getMethods() as $method) {
            $mappingFound = false;
            foreach (self::HTTP_MAPPINGS as $type => $httpMethod) {
                $attributes = $method->getAttributes($type);
                if ($attributes === []) {
                    continue;
                }
                if (count($attributes) > 1) {
                    throw new \LogicException('Duplicate ' . $type . ' on ' . $class . '::' . $method->getName());
                }
                if ($controller === null) {
                    throw new \LogicException('Route requires Controller: ' . $class);
                }
                $mappingFound = true;
                $mapping = $attributes[0]->newInstance();
                $path = $mapping->path ?? '/';
                $this->appendRoute($reflection, $prefix, $classMiddleware, $httpMethod, (string) $path, '', [], false, $method->getName());
            }
            $httpRoutes = $method->getAttributes(HttpRoute::class);
            if ($httpRoutes !== []) {
                if ($controller === null) {
                    throw new \LogicException('Route requires Controller: ' . $class);
                }
                if ($mappingFound) {
                    throw new \LogicException('HttpRoute conflicts with mapping on ' . $class . '::' . $method->getName());
                }
                foreach ($httpRoutes as $attribute) {
                    $route = $attribute->newInstance();
                    $this->appendRoute($reflection, $prefix, $classMiddleware, $route->method, $route->path, $route->name, $route->middleware, $route->websocket, $method->getName());
                }
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
        if (self::attribute($reflection, \Cli\Annotation\Command::class) !== null) {
            if (!$reflection->isInstantiable() || !$reflection->isSubclassOf(\Loom\Console\Command::class)) {
                throw new \LogicException('Command requires a concrete Command subclass: ' . $class);
            }
            $this->commands[] = $class;
        }
        foreach ($reflection->getAttributes(\Loom\Attributes\Listens::class) as $attribute) {
            $listens = $attribute->newInstance();
            $this->handler($reflection, $listens->method);
            if ($listens->queued) {
                $eventReflection = new \ReflectionClass($listens->event);
                if (!$eventReflection->implementsInterface(\Loom\Events\QueueableEvent::class)) {
                    throw new \LogicException('Queued listener requires QueueableEvent: ' . $listens->event);
                }
            }
            if (!isset($this->eventListeners[$listens->event])) {
                $this->eventListeners[$listens->event] = [];
            }
            foreach ($this->eventListeners[$listens->event] as $existing) {
                if ($existing[0] === $class) {
                    throw new \LogicException('Duplicate event listener: ' . $class . ' for ' . $listens->event);
                }
            }
            $this->eventListeners[$listens->event][] = [$class, $listens->method, $listens->queued];
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
     * @param list<string> $classMiddleware
     * @param list<string> $routeMiddleware
     */
    private function appendRoute(
        \ReflectionClass $class,
        string $prefix,
        array $classMiddleware,
        string $method,
        string $path,
        string $name,
        array $routeMiddleware,
        bool $websocket,
        string $handlerMethod,
    ): void {
        if ($handlerMethod === '__invoke') {
            throw new \LogicException('Route handler must not use __invoke: ' . $class->getName());
        }
        $this->handler($class, $handlerMethod);
        if ($websocket && strtoupper($method) !== 'GET') {
            throw new \LogicException('WebSocket route requires GET');
        }
        $middleware = array_merge($classMiddleware, $routeMiddleware);
        foreach ($middleware as $entry) {
            if (!is_string($entry) || $entry === '') {
                throw new \LogicException('Attribute middleware must use service class names; register instances from a service provider');
            }
        }
        $this->routes[] = [
            'method' => $method,
            'path' => rtrim($prefix, '/') . $path,
            'action' => [$class->getName(), $handlerMethod],
            'name' => $name,
            'middleware' => $middleware,
            'websocket' => $websocket,
        ];
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
     * 返回通过 Command 注解校验的命令类名，顺序与发现顺序一致。不实例化命令。
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

    /**
     * @return array<string, list<array{0: string, 1: string, 2: bool}>>
     */
    public function eventListeners(): array
    {
        return $this->eventListeners;
    }
}
