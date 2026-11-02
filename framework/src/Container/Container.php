<?php

declare(strict_types=1);

namespace Loom;

/**
 * 应用根容器保存注册和 singleton；派生容器只缓存自己的 scoped 服务。
 * 未注册的具体类默认 scoped。所有生命周期都接受实现类名或工厂。
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
    private int $singletonDepth = 0;

    public function __construct(?ObjectFactory $objects = null, ?Container $parent = null)
    {
        $this->native = new \Container\Container();
        $this->objects = $objects ?? new ConfiguredObjectFactory();
        $this->root = $parent === null ? $this : $parent->root;
    }

    /** 新作用域共享注册规则和应用单例，不继承 scoped 实例。 */
    public function fork(): self
    {
        return new self($this->objects, $this->root);
    }

    public function has(string $id): bool
    {
        return $id === self::class || $this->native->has($id) || isset($this->instances[$id]) || isset($this->root->sharedInstances[$id]) || isset($this->root->definitions[$id]);
    }

    /** 只读取已缓存对象，不创建对象。 */
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

    /** 只读取当前作用域持有的缓存，供请求资源清理使用。 */
    public function resolvedLocal(string $id): ?object
    {
        $id = $this->canonicalId($id);
        if (($this->root->definitions[$id]['lifetime'] ?? '') === 'singleton' || isset($this->root->sharedInstances[$id])) {
            return null;
        }
        return $this->instances[$id] ?? $this->resolved[$id] ?? null;
    }

    /** 预构造实例在应用树内共享，由调用方负责资源释放。 */
    public function instance(string $id, object $instance): void
    {
        $this->assertAvailable($id);
        $this->root->sharedInstances[$id] = $instance;
    }

    /** 仅向当前作用域绑定宿主上下文，不会传播到子作用域。 */
    public function scopedInstance(string $id, object $instance): void
    {
        if ($id === self::class || $this->native->has($id) || isset($this->instances[$id]) || isset($this->root->definitions[$id]) || isset($this->root->sharedInstances[$id])) {
            throw new \LogicException('Duplicate service: ' . $id);
        }
        $this->instances[$id] = $instance;
    }

    /** 兼容旧工厂注册；旧 bind 的作用域共享语义保持不变。 */
    public function bind(string $id, callable $factory): void
    {
        $this->scoped($id, $factory);
    }

    public function singleton(string $id, string|callable|null $implementation = null): void
    {
        $this->register($id, $implementation, 'singleton');
    }

    public function scoped(string $id, string|callable|null $implementation = null): void
    {
        $this->register($id, $implementation, 'scoped');
    }

    public function transient(string $id, string|callable|null $implementation = null): void
    {
        $this->register($id, $implementation, 'transient');
    }

    /** 纯访问别名；不会另行创建或缓存目标对象。 */
    public function alias(string $id, string $target): void
    {
        $this->assertAvailable($id);
        $this->root->definitions[$id] = ['alias' => $target];
    }

    /** 应用配置在根容器上串行安装一次；回调应只注册服务，不解析请求状态。 */
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

    private function assertAvailable(string $id): void
    {
        if ($this->has($id)) {
            throw new \LogicException('Duplicate service: ' . $id);
        }
    }

    private function register(string $id, string|callable|null $implementation, string $lifetime): void
    {
        $this->assertAvailable($id);
        $this->root->definitions[$id] = ['implementation' => $implementation ?? $id, 'lifetime' => $lifetime];
    }

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

    private function rejectCaptive(string $id): void
    {
        if ($this->singletonDepth > 0) {
            throw new \LogicException('Singleton cannot depend on scoped service: ' . $id);
        }
    }

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
