<?php

declare(strict_types=1);

namespace Loom;

// 每个 Loom 作用域拥有独立的标准库容器，禁止使用全局单例。
/**
 * 封装 Origami 原生容器，提供作用域单例、瞬时服务、别名及配置注入，拒绝重复注册。
 *
 * 构造参数：
 * - $objects（?ObjectFactory）：负责构造及初始化对象的工厂；null 时使用 ConfiguredObjectFactory。默认值为 null。
 *
 * 返回值：使用 new 构造时得到 Container 实例；注册到容器时由容器管理实例的创建与复用。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $services = new Container(); $services->singleton(RequestContext::class);
 */
final class Container
{
    private \Container\Container $native;
    private ObjectFactory $objects;
    private array $resolved = [];
    /**
     * 初始化 Container 实例及其依赖。封装 Origami 原生容器，提供作用域单例、瞬时服务、别名及配置注入，拒绝重复注册。
     *
     * @param ?ObjectFactory $objects 负责构造及初始化对象的工厂；null 时使用 ConfiguredObjectFactory。默认值为 null。
     * @return void 无直接返回值；通过 new 表达式取得初始化后的实例。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $services = new Container(); $services->singleton(RequestContext::class);
     */
    public function __construct(?ObjectFactory $objects = null)
    {
        $this->native = new \Container\Container();
        $this->objects = $objects ?? new ConfiguredObjectFactory();
    }
    /**
     * 检查服务标识是否已经注册，不触发实例构造。
     *
     * @param string $id 服务唯一标识，可使用完整类名或自定义字符串。
     * @return bool 已注册为 true，否则 false。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $exists = $services->has(RequestContext::class);
     */
    public function has(string $id): bool
    {
        return $this->native->has($id);
    }
    /**
     * 只读取已经解析并缓存的共享实例，不触发构造。
     *
     * @param string $id 服务唯一标识，可使用完整类名或自定义字符串。
     * @return ?object 已缓存对象；未解析或非共享服务返回 null。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $context = $services->resolved(RequestContext::class);
     */
    public function resolved(string $id): ?object
    {
        return $this->resolved[$id] ?? null;
    }
    /**
     * 注册已构造对象，并立即将其记录为已解析共享实例。
     *
     * @param string $id 服务唯一标识，可使用完整类名或自定义字符串。
     * @param object $instance 已构造、需要共享的服务对象。
     * @return void 无返回值；标识重复时抛出 LogicException。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $services->instance(Config::class, $config);
     */
    public function instance(string $id, object $instance): void
    {
        if ($this->has($id)) {
            throw new \LogicException('Duplicate service: ' . $id);
        }
        $this->native->singleton($id, function ($native) use ($instance): object {
            return $instance;
        });
        $this->resolved[$id] = $instance;
    }
    /**
     * 以工厂注册作用域单例，是 singleton 的便捷别名。
     *
     * @param string $id 服务唯一标识，可使用完整类名或自定义字符串。
     * @param callable $factory 接收当前 Container 并返回对象的工厂；允许 null 的方法会自动按类名构造。
     * @return void 无返回值。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $services->bind('clock', fn (Container $c) => new \DateTimeImmutable());
     */
    public function bind(string $id, callable $factory): void
    {
        $this->singleton($id, $factory);
    }
    /**
     * 注册当前容器内共享的服务，首次 get 时构造。
     *
     * @param string $id 服务唯一标识，可使用完整类名或自定义字符串。
     * @param ?callable $factory 接收当前 Container 并返回对象的工厂；允许 null 的方法会自动按类名构造。默认值为 null。
     * @return void 无返回值；重复标识会报错。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $services->singleton(RequestContext::class);
     */
    public function singleton(string $id, ?callable $factory = null): void
    {
        $this->register($id, $factory, true);
    }
    /**
     * 注册每次 get 都重新构造的服务，不记录到共享实例缓存。
     *
     * @param string $id 服务唯一标识，可使用完整类名或自定义字符串。
     * @param ?callable $factory 接收当前 Container 并返回对象的工厂；允许 null 的方法会自动按类名构造。默认值为 null。
     * @return void 无返回值。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $services->transient('clock', fn (Container $c) => new \DateTimeImmutable());
     */
    public function transient(string $id, ?callable $factory = null): void
    {
        $this->register($id, $factory, false);
    }
    /**
     * 将服务标识委托给另一标识，每次解析都遵守目标服务生命周期。
     *
     * @param string $id 服务唯一标识，可使用完整类名或自定义字符串。
     * @param string $target 别名指向的目标服务标识。
     * @return void 无返回值；别名标识已存在时会报错。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $services->alias('request.context', RequestContext::class);
     */
    public function alias(string $id, string $target): void
    {
        if ($this->has($id)) {
            throw new \LogicException('Duplicate service: ' . $id);
        }
        $services = $this;
        // 委托每次查找，使别名保留目标的生命周期和初始化行为。
        $this->native->bind($id, function ($native) use ($services, $target): object {
            return $services->get($target);
        });
    }
    /**
     * 包装工厂或自动构造器，统一进行属性初始化并按 shared 选择生命周期。
     *
     * @param string $id 服务唯一标识，可使用完整类名或自定义字符串。
     * @param ?callable $factory 接收当前 Container 并返回对象的工厂；允许 null 的方法会自动按类名构造。
     * @param bool $shared true 缓存当前容器内实例，false 每次解析重新构造。
     * @return void 无返回值；工厂必须返回对象。
     *
     * 内部调用示例（仅类内使用）：
     * @example $this->register(RequestContext::class, null, true);
     */
    private function register(string $id, ?callable $factory, bool $shared): void
    {
        if ($this->has($id)) {
            throw new \LogicException('Duplicate service: ' . $id);
        }
        $services = $this;
        $objects = $this->objects;
        $resolve = function ($native) use ($services, $factory, $id, $objects, $shared): object {
            $instance = $factory === null ? $objects->create($id, $services) : $factory($services);
            if (!is_object($instance)) {
                throw new \LogicException('Service factory must return an object: ' . $id);
            }
            $objects->initialize($instance, $services);
            if ($shared) {
                $services->resolved[$id] = $instance;
            }
            return $instance;
        };
        if ($shared) {
            $this->native->singleton($id, $resolve);
        } else {
            $this->native->bind($id, $resolve);
        }
    }
    /**
     * 解析服务，未注册类名自动注册为单例；把原生循环依赖异常转换为 LogicException。
     *
     * @param string $id 服务唯一标识，可使用完整类名或自定义字符串。
     * @return object 完成构造和配置属性注入的服务对象。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $context = $services->get(RequestContext::class);
     */
    public function get(string $id): object
    {
        if (!$this->has($id)) {
            $this->singleton($id);
        }
        try {
            return $this->native->make($id);
        } catch (\Container\CircularDependencyException $error) {
            throw new \LogicException('Dependency cycle: ' . $id);
        }
    }
}
