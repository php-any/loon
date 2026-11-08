<?php

declare(strict_types=1);

namespace Loom;

/**
 * 根容器，由 ServiceCollection::build() 生成，对应 ASP.NET Core 的根 IServiceProvider 加 IServiceScopeFactory。
 *
 * 根容器只持有单例。Scoped 服务必须在 createScope() 得到的 ServiceScope 里解析；在根上解析，或单例构造时依赖它，都会抛出 LogicException，
 * 这样单例不会捕获某一次请求的对象。单例第一次创建时持有 Go 侧的锁，并发解析只构造一次。
 *
 * 构造参数或工厂参数可以声明 ServiceProvider（总是根容器）、ServiceResolver（发起解析的容器）或 ServiceScope（当前作用域）。
 *
 * Origami 的 HTTP 回调在请求虚拟机里执行，请求里对脚本对象的写入只对这一次请求可见。需要整个进程共用的单例，要在开始监听之前解析出来。
 *
 * @example $provider = $services->build(); $provider->withScope(fn (ServiceScope $scope) => $scope->get(Clock::class)->now());
 */
final class ServiceProvider implements ServiceResolver
{
    /** @var array<string, list<ServiceDescriptor>> */
    private array $descriptors;
    /** @var array<string, object> */
    private array $singletons = [];
    /** @var list<Disposable> */
    private array $disposables = [];
    /** @var array<string, ServiceDescriptor> */
    private array $implicit = [];
    /** @var array<string, string> */
    private array $resolving = [];
    private bool $disposed = false;

    /**
     * 由 ServiceCollection::build() 调用。
     *
     * @param array<string, list<ServiceDescriptor>> $descriptors 冻结后的登记表。
     */
    public function __construct(array $descriptors)
    {
        $this->descriptors = $descriptors;
    }

    public function get(string $id): object
    {
        return $this->resolve($id, null);
    }

    public function tryGet(string $id): ?object
    {
        return $this->has($id) ? $this->get($id) : null;
    }

    public function getAll(string $id): array
    {
        return $this->resolveAll($id, null);
    }

    public function has(string $id): bool
    {
        return isset($this->descriptors[$id]) || $id === self::class || $id === ServiceResolver::class;
    }

    /**
     * 新建作用域。调用方负责 dispose()，或改用 withScope()。
     */
    public function createScope(): ServiceScope
    {
        $this->assertActive();
        return new ServiceScope($this);
    }

    /**
     * 打开一个作用域，在其中执行一次工作单元，结束时释放作用域。回调收到这个 ServiceScope，返回值原样返回。
     * 作用域里的模型静态查询使用该作用域的 Database。
     *
     * @example $count = $provider->withScope(static fn (ServiceScope $scope): int => Task::query()->count());
     */
    public function withScope(callable $operation): mixed
    {
        $scope = $this->createScope();
        try {
            $result = $scope->run($operation);
        } catch (\Throwable $error) {
            try {
                $scope->dispose();
            } catch (\Throwable) {
            }
            throw $error;
        }
        $scope->dispose();
        return $result;
    }

    /**
     * 按创建的相反顺序释放容器创建的单例。之后不能再解析服务。重复调用直接返回。
     */
    public function dispose(): void
    {
        if ($this->disposed) {
            return;
        }
        $this->disposed = true;
        $disposables = $this->disposables;
        $this->disposables = [];
        $this->singletons = [];
        $failure = null;
        foreach (array_reverse($disposables) as $service) {
            try {
                $service->dispose();
            } catch (\Throwable $error) {
                $failure ??= $error;
            }
        }
        if ($failure !== null) {
            throw $failure;
        }
    }

    /**
     * @internal 由 ServiceScope 调用。$scope 为 null 表示在根上解析。
     */
    public function resolve(string $id, ?ServiceScope $scope): object
    {
        $this->assertActive();
        if ($id === self::class) {
            return $this;
        }
        if ($id === ServiceResolver::class) {
            return $scope ?? $this;
        }
        if ($id === ServiceScope::class) {
            if ($scope === null) {
                throw new \LogicException('ServiceScope can only be resolved inside a scope');
            }
            return $scope;
        }
        $descriptors = $this->descriptors[$id] ?? null;
        if ($descriptors === null) {
            return $this->resolveDescriptor($this->implicit($id), $id . '#implicit', $scope);
        }
        $index = count($descriptors) - 1;
        return $this->resolveDescriptor($descriptors[$index], $id . '#' . $index, $scope);
    }

    /**
     * @internal 由 ServiceScope 调用。
     *
     * @return list<object>
     */
    public function resolveAll(string $id, ?ServiceScope $scope): array
    {
        $this->assertActive();
        $services = [];
        foreach ($this->descriptors[$id] ?? [] as $index => $descriptor) {
            $services[] = $this->resolveDescriptor($descriptor, $id . '#' . $index, $scope);
        }
        return $services;
    }

    /**
     * @internal 按登记创建对象：类名走构造注入，工厂收到发起解析的容器。随后注入 InjectConfig 属性并检查类型。
     */
    public static function activate(ServiceDescriptor $descriptor, ServiceResolver $services): object
    {
        $implementation = $descriptor->implementation ?? $descriptor->id;
        $instance = is_string($implementation)
            ? ConfigInjector::create($implementation, $services)
            : $implementation($services);
        if (!is_object($instance)) {
            throw new \LogicException('Service factory must return an object: ' . $descriptor->id);
        }
        if ((class_exists($descriptor->id) || interface_exists($descriptor->id)) && !($instance instanceof $descriptor->id)) {
            throw new \LogicException('Service must implement ' . $descriptor->id);
        }
        ConfigInjector::inject($instance, $services);
        return $instance;
    }

    /**
     * @internal 记录正在创建的服务，发现循环依赖时抛出 LogicException。
     */
    public static function guard(array &$resolving, string $key, string $id, callable $create): object
    {
        if (isset($resolving[$key])) {
            throw new \LogicException('Dependency cycle: ' . implode(' -> ', array_values($resolving)) . ' -> ' . $id);
        }
        $resolving[$key] = $id;
        try {
            return $create();
        } finally {
            unset($resolving[$key]);
        }
    }

    private function resolveDescriptor(ServiceDescriptor $descriptor, string $key, ?ServiceScope $scope): object
    {
        if ($descriptor->instance !== null) {
            return $descriptor->instance;
        }
        if ($descriptor->lifetime === ServiceLifetime::Singleton) {
            return $this->singleton($descriptor, $key);
        }
        if ($descriptor->lifetime === ServiceLifetime::Scoped) {
            if ($scope === null) {
                throw new \LogicException($this->resolving === []
                    ? 'Cannot resolve scoped service ' . $descriptor->id . ' from the root provider; create a scope first'
                    : 'Singleton ' . end($this->resolving) . ' cannot depend on scoped service ' . $descriptor->id);
            }
            return $scope->scoped($descriptor, $key);
        }
        if ($scope !== null) {
            return $scope->transient($descriptor, $key);
        }
        $provider = $this;
        $instance = self::guard($this->resolving, $key, $descriptor->id, static fn (): object => self::activate($descriptor, $provider));
        if ($instance instanceof Disposable) {
            $this->disposables[] = $instance;
        }
        return $instance;
    }

    private function singleton(ServiceDescriptor $descriptor, string $key): object
    {
        if (isset($this->singletons[$key])) {
            return $this->singletons[$key];
        }
        $provider = $this;
        return \Loom\Native\withSingletonLock($this, static function () use ($provider, $descriptor, $key): object {
            return $provider->createSingleton($descriptor, $key);
        });
    }

    /**
     * @internal 只在单例锁内调用。
     */
    public function createSingleton(ServiceDescriptor $descriptor, string $key): object
    {
        if (isset($this->singletons[$key])) {
            return $this->singletons[$key];
        }
        $provider = $this;
        $instance = self::guard($this->resolving, $key, $descriptor->id, static fn (): object => self::activate($descriptor, $provider));
        $this->singletons[$key] = $instance;
        if ($instance instanceof Disposable) {
            $this->disposables[] = $instance;
        }
        return $instance;
    }

    private function implicit(string $id): ServiceDescriptor
    {
        if (isset($this->implicit[$id])) {
            return $this->implicit[$id];
        }
        if (!class_exists($id) || !(new \ReflectionClass($id))->isInstantiable()) {
            throw new \LogicException('Service is not registered: ' . $id);
        }
        return $this->implicit[$id] = ServiceDescriptor::scoped($id);
    }

    private function assertActive(): void
    {
        if ($this->disposed) {
            throw new \LogicException('The service provider has been disposed');
        }
    }
}
