<?php

declare(strict_types=1);

namespace Loom;

/**
 * 应用根容器保存注册和 singleton；派生容器只缓存自己的 scoped 服务。
 * 未注册的具体类默认 scoped。所有生命周期都接受实现类名或工厂。
 * options() 把配置节绑定为单例，lifetime() 报告解析别名后的生命周期。
 *
 * 构造参数：
 * - $objects（?ObjectFactory）：创建和初始化服务对象的工厂。默认使用 ConfiguredObjectFactory。
 * - $parent（?Container）：派生容器的根容器。null 表示当前对象就是应用根容器。
 *
 * 返回值：使用 new 构造时得到 Container 实例。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $services = new Container(); $services->singleton(Clock::class);
 */
final class Container
{
    private \Container\Container $native;
    private ObjectFactory $objects;
    private Container $root;
    private array $definitions = [];
    private array $instances = [];
    private array $sharedInstances = [];
    private array $resolved = [];
    private array $configured = [];
    private array $optionValidators = [];
    private int $singletonDepth = 0;

    /**
     * 建立原生容器、对象工厂和根容器指针。parent 为空时自己就是根容器。
     *
     * @param ?ObjectFactory $objects 创建和初始化服务对象的工厂。null 时使用 ConfiguredObjectFactory。
     * @param ?Container $parent 派生容器要共享的根容器。null 表示新建独立应用容器。
     * @return void 无直接返回值。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $child = new Container(null, $root);
     */
    public function __construct(?ObjectFactory $objects = null, ?Container $parent = null)
    {
        $this->native = new \Container\Container();
        $this->objects = $objects ?? new ConfiguredObjectFactory();
        $this->root = $parent === null ? $this : $parent->root;
    }

    /**
     * 派生一个新容器。它共享根容器上的注册、别名和 singleton，并使用空的 scoped 缓存。
     *
     * 参数：无。
     *
     * @return self 新的 Container。调用方负责它解析出来的作用域资源。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $requestServices = $services->fork();
     */
    public function fork(): self
    {
        return new self($this->objects, $this->root);
    }

    /**
     * 判断标识是否已有注册、共享实例、当前作用域实例，或就是容器自身。
     * 不创建对象。未注册的具体类在 get() 时才会自动登记为 scoped，因此这里返回 false。
     *
     * @param string $id 服务标识，通常是类名、接口名或别名。
     * @return bool 已占用时为 true。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example if (!$services->has(Clock::class)) { $services->singleton(Clock::class); }
     */
    public function has(string $id): bool
    {
        return $id === self::class || $this->native->has($id) || isset($this->instances[$id]) || isset($this->root->sharedInstances[$id]) || isset($this->root->definitions[$id]);
    }

    /**
     * 读取已经创建的对象，不触发工厂。singleton 看根容器缓存，其他生命周期看当前容器缓存。
     *
     * @param string $id 服务标识。别名会先解析到目标标识。
     * @return ?object 已创建的实例；尚未解析时返回 null。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $existing = $services->resolved(Clock::class);
     */
    public function resolved(string $id): ?object
    {
        $id = $this->canonicalId($id);
        if (isset($this->instances[$id])) {
            return $this->instances[$id];
        }
        if (isset($this->root->sharedInstances[$id])) {
            return $this->root->sharedInstances[$id];
        }
        return ($this->root->definitions[$id]['lifetime'] ?? '') === 'singleton'
            ? ($this->root->resolved[$id] ?? null) : ($this->resolved[$id] ?? null);
    }

    /**
     * 只读取当前作用域自己缓存的对象，供请求结束时清理。singleton 和 instance() 共享对象返回 null，避免一个作用域关掉其他作用域仍在使用的实例。
     *
     * @param string $id 服务标识。别名会先解析到目标标识。
     * @return ?object 当前作用域持有的实例；不属于本次清理范围时返回 null。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $database = $services->resolvedLocal(Database::class);
     */
    public function resolvedLocal(string $id): ?object
    {
        $id = $this->canonicalId($id);
        if (($this->root->definitions[$id]['lifetime'] ?? '') === 'singleton' || isset($this->root->sharedInstances[$id])) {
            return null;
        }
        return $this->instances[$id] ?? $this->resolved[$id] ?? null;
    }

    /**
     * 登记调用方已经构造好的对象，并让整棵应用容器树共享它。容器不推断它的释放方式。
     *
     * @param string $id 服务标识。不能与已有注册或实例重复。
     * @param object $instance 要共享的对象，必须满足该标识已知的类型。
     * @return void 无返回值。重复标识抛出 LogicException。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $services->instance(Config::class, $config);
     */
    public function instance(string $id, object $instance): void
    {
        $this->assertAvailable($id);
        $this->root->sharedInstances[$id] = $instance;
    }

    /**
     * 把已有对象只放进当前容器。子作用域看不到它，它也不进入根容器的共享表。
     *
     * @param string $id 服务标识。不能与已有注册、共享实例或当前实例重复。
     * @param object $instance 只属于当前作用域的对象。
     * @return void 无返回值。重复标识抛出 LogicException。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $services->scopedInstance(ServiceScope::class, $scope);
     */
    public function scopedInstance(string $id, object $instance): void
    {
        if ($id === self::class || $this->native->has($id) || isset($this->instances[$id]) || isset($this->root->definitions[$id]) || isset($this->root->sharedInstances[$id])) {
            throw new \LogicException('Duplicate service: ' . $id);
        }
        $this->instances[$id] = $instance;
    }

    /**
     * 按旧入口登记工厂，语义与 scoped() 相同：同一作用域复用第一次创建的对象。
     *
     * @param string $id 服务标识。
     * @param callable $factory 接收 Container 并返回对象的工厂。
     * @return void 无返回值。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $services->bind(Repository::class, static fn (Container $services) => new SqlRepository($services->get(Database::class)));
     */
    public function bind(string $id, callable $factory): void
    {
        $this->scoped($id, $factory);
    }

    /**
     * 登记应用级单例。同一个根容器及其所有 fork 共享第一次成功创建的对象。
     * 工厂在根容器上执行，不能依赖 scoped 服务。
     *
     * @param string $id 服务标识。
     * @param string|callable|null $implementation 实现类名或工厂。null 表示按 $id 构造。
     * @return void 无返回值。重复标识抛出 LogicException。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $services->singleton(Clock::class);
     */
    public function singleton(string $id, string|callable|null $implementation = null): void
    {
        $this->register($id, $implementation, 'singleton');
    }

    /**
     * 登记作用域服务。每个容器第一次 get() 时创建，之后只在该容器内复用。未显式注册的具体类也走这个生命周期。
     *
     * @param string $id 服务标识。
     * @param string|callable|null $implementation 实现类名或工厂。null 表示按 $id 构造。
     * @return void 无返回值。重复标识抛出 LogicException。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $services->scoped(Database::class);
     */
    public function scoped(string $id, string|callable|null $implementation = null): void
    {
        $this->register($id, $implementation, 'scoped');
    }

    /**
     * 登记瞬时服务。每次 get() 都创建新对象，不写入作用域缓存。
     *
     * @param string $id 服务标识。
     * @param string|callable|null $implementation 实现类名或工厂。null 表示按 $id 构造。
     * @return void 无返回值。重复标识抛出 LogicException。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $services->transient(Handler::class);
     */
    public function transient(string $id, string|callable|null $implementation = null): void
    {
        $this->register($id, $implementation, 'transient');
    }

    /**
     * 给已有服务增加另一个名字。解析时沿别名找到目标，再使用目标自己的生命周期和缓存，不额外创建对象。
     *
     * @param string $id 新的服务标识。
     * @param string $target 已登记或将要登记的目标标识。
     * @return void 无返回值。重复标识或别名成环会在注册或解析时抛出 LogicException。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $services->alias(ClockInterface::class, Clock::class);
     */
    public function alias(string $id, string $target): void
    {
        $this->assertAvailable($id);
        $this->root->definitions[$id] = ['alias' => $target];
    }

    /**
     * 把配置节绑定成单例选项对象。第一次 get($class) 时读取配置并执行可选校验，成功后各作用域复用该对象。
     * 校验失败不缓存，下次解析会重试。校验闭包保存在根容器上，避免被工厂闭包捕获后调用不到。
     *
     * @param string $class 选项类名。类须能无必填构造参数地创建。
     * @param string $section 点分配置节，例如 payment。对应值必须是数组。
     * @param ?callable $validate 接收绑定后的对象。null 表示只做类型绑定。
     * @return void 无返回值。类名或配置节为空时抛出 InvalidArgumentException。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $services->options(PaymentOptions::class, 'payment'); $payment = $services->get(PaymentOptions::class);
     */
    public function options(string $class, string $section, ?callable $validate = null): void
    {
        if ($class === '' || $section === '') {
            throw new \InvalidArgumentException('Options class and section must not be empty');
        }
        $root = $this->root;
        $root->optionValidators[$class] = $validate;
        $this->singleton($class, static function (Container $services) use ($class, $section, $root): object {
            // Config 是共享实例。这里不解析 Options 单例，避免在单例构造期间再次进入单例锁。
            $options = (new \Loom\Options($services->get(\Loom\Config::class)))->bind($class, $section);
            $root->applyOptionValidator($class, $options);
            return $options;
        });
    }

    /**
     * 执行 options() 存下的校验闭包。没有校验时直接返回。
     *
     * @param string $class 选项类名，用作校验表的键。
     * @param object $options 刚刚绑定好的选项对象。
     * @return void 无返回值。校验闭包抛出的异常原样传播。
     *
     * 内部调用示例（仅类内使用）：
     * @example $root->applyOptionValidator($class, $options);
     */
    private function applyOptionValidator(string $class, object $options): void
    {
        $validate = $this->optionValidators[$class] ?? null;
        if ($validate !== null) {
            $validate($options);
        }
    }

    /**
     * 报告标识最终的生命周期。先沿别名找到目标；instance() 放入的共享对象视为 singleton。
     * 尚未注册时返回 null。scopedInstance() 只存在于当前容器，不在这里报告。
     *
     * @param string $id 服务标识或别名。
     * @return ?string singleton、scoped、transient 之一；未注册时返回 null。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example if ($services->lifetime(Worker::class) === null) { $services->singleton(Worker::class); }
     */
    public function lifetime(string $id): ?string
    {
        $id = $this->canonicalId($id);
        if (isset($this->root->definitions[$id]['lifetime'])) {
            return $this->root->definitions[$id]['lifetime'];
        }
        if (isset($this->root->sharedInstances[$id])) {
            return 'singleton';
        }
        return null;
    }

    /**
     * 在根容器上按键执行一次安装回调。并发调用由原生单例锁串行化，已经安装过的键直接跳过。
     * 回调收到的是根容器，应只登记服务，不要解析请求期对象。
     *
     * @param string $key 安装项标识，例如 config/services.php。
     * @param callable $configure 接收根 Container 的安装回调。
     * @return void 无返回值。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $services->configureOnce('config/services.php', $configure);
     */
    public function configureOnce(string $key, callable $configure): void
    {
        $root = $this->root;
        \Loom\Native\withSingletonLock($root->native, static function () use ($root, $key, $configure): void {
            if (!isset($root->configured[$key])) {
                $configure($root);
                $root->configured[$key] = true;
            }
        });
    }

    /**
     * 拒绝重复占用同一个服务标识。
     *
     * @param string $id 准备登记的服务标识。
     * @return void 无返回值。has($id) 为真时抛出 LogicException。
     *
     * 内部调用示例（仅类内使用）：
     * @example $this->assertAvailable($id);
     */
    private function assertAvailable(string $id): void
    {
        if ($this->has($id)) {
            throw new \LogicException('Duplicate service: ' . $id);
        }
    }

    /**
     * 把生命周期和实现写入根容器的注册表。实现为空时使用标识本身作为类名。
     *
     * @param string $id 服务标识。
     * @param string|callable|null $implementation 实现类名或工厂。
     * @param string $lifetime singleton、scoped 或 transient。
     * @return void 无返回值。
     *
     * 内部调用示例（仅类内使用）：
     * @example $this->register($id, $implementation, 'scoped');
     */
    private function register(string $id, string|callable|null $implementation, string $lifetime): void
    {
        $this->assertAvailable($id);
        $this->root->definitions[$id] = ['implementation' => $implementation ?? $id, 'lifetime' => $lifetime];
    }

    /**
     * 按标识取得对象。别名先解析到目标。容器自身、当前作用域实例和共享实例直接返回。
     * 未注册的具体类自动登记为 scoped。singleton 在根容器上构造并加锁；构造期间再取 scoped 服务会失败。
     * transient 每次创建。工厂失败不缓存，下次 get() 可以重试。
     *
     * @param string $id 服务标识、类名或别名。
     * @return object 符合该标识类型的实例。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $clock = $services->get(Clock::class);
     */
    public function get(string $id): object
    {
        $id = $this->canonicalId($id);
        if ($id === self::class) {
            $this->rejectCaptive($id);
            return $this;
        }
        if (isset($this->instances[$id])) {
            $this->rejectCaptive($id);
            return $this->instances[$id];
        }
        if (isset($this->root->sharedInstances[$id])) {
            return $this->root->sharedInstances[$id];
        }
        if (!isset($this->root->definitions[$id])) {
            $this->configureOnce('container.auto:' . $id, static function (Container $services) use ($id): void {
                if (!$services->has($id)) {
                    $services->scoped($id);
                }
            });
        }
        $definition = $this->root->definitions[$id];
        if ($definition['lifetime'] === 'singleton') {
            $root = $this->root;
            // 原生容器缓存共享对象；Go 锁覆盖完整解析链，避免并发重复构造和误报循环依赖。
            return \Loom\Native\withSingletonLock($root->native, static function () use ($root, $id, $definition): object {
                $root->singletonDepth++;
                try {
                    return $root->resolve($id, $definition);
                } finally {
                    $root->singletonDepth--;
                }
            });
        }
        if ($definition['lifetime'] === 'scoped') {
            $this->rejectCaptive($id);
        }
        return $this->resolve($id, $definition);
    }

    /**
     * 沿别名链找到最终标识。环路抛出 LogicException。没有别名时原样返回。
     *
     * @param string $id 起始服务标识。
     * @return string 别名指向的最终标识。
     *
     * 内部调用示例（仅类内使用）：
     * @example $id = $this->canonicalId($id);
     */
    private function canonicalId(string $id): string
    {
        $seen = [];
        while (isset($this->root->definitions[$id]['alias'])) {
            if (isset($seen[$id])) {
                throw new \LogicException('Dependency cycle: ' . $id);
            }
            $seen[$id] = true;
            $id = $this->root->definitions[$id]['alias'];
        }
        return $id;
    }

    /**
     * 在 singleton 构造期间拒绝再解析当前容器、作用域实例或 scoped 服务，避免单例捕获请求期对象。
     *
     * @param string $id 正要解析的服务标识，只用于异常文本。
     * @return void 无返回值。singletonDepth 大于 0 时抛出 LogicException。
     *
     * 内部调用示例（仅类内使用）：
     * @example $this->rejectCaptive($id);
     */
    private function rejectCaptive(string $id): void
    {
        if ($this->singletonDepth > 0) {
            throw new \LogicException('Singleton cannot depend on scoped service: ' . $id);
        }
    }

    /**
     * 把注册交给原生容器创建，并检查工厂返回值。类名实现走对象工厂，闭包实现接收当前 Loom 容器。
     * singleton 和 scoped 缓存本次对象；transient 不缓存。原生循环依赖转换成 LogicException。
     *
     * @param string $id 已经解析过别名的服务标识。
     * @param array $definition 根容器上的实现和生命周期。
     * @return object 新创建或原生容器缓存的实例。
     *
     * 内部调用示例（仅类内使用）：
     * @example return $this->resolve($id, $definition);
     */
    private function resolve(string $id, array $definition): object
    {
        if (!$this->native->has($id)) {
            $services = $this;
            $implementation = $definition['implementation'];
            $shared = $definition['lifetime'] !== 'transient';
            $factory = static function ($native) use ($services, $id, $implementation, $shared): object {
                $instance = is_string($implementation)
                    ? $services->objects->create($implementation, $services)
                    : $implementation($services);
                if (!is_object($instance)) {
                    throw new \LogicException('Service factory must return an object: ' . $id);
                }
                if ((interface_exists($id) || class_exists($id)) && !($instance instanceof $id)) {
                    throw new \LogicException('Service must implement ' . $id);
                }
                $services->objects->initialize($instance, $services);
                if ($shared) {
                    $services->resolved[$id] = $instance;
                }
                return $instance;
            };
            if ($shared) {
                $this->native->singleton($id, $factory);
            } else {
                $this->native->bind($id, $factory);
            }
        }
        try {
            return $this->native->make($id);
        } catch (\Container\CircularDependencyException $error) {
            throw new \LogicException('Dependency cycle: ' . $id);
        }
    }
}
