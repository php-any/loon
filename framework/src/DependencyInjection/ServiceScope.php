<?php

declare(strict_types=1);

namespace Loom;

/**
 * 一个工作单元的服务作用域，对应 ASP.NET Core 的 IServiceScope。宿主为每次 HTTP 请求、TCP 消息、gRPC 调用、命令、队列节拍和定时器节拍各建一个。
 *
 * Scoped 服务在这里各建一份，作用域释放时按创建的相反顺序释放其中的 Disposable。单例从根容器取。
 * 作用域不拥有数据库连接：Database 只保存这次工作里的事务和命名连接，连接池在进程级。
 *
 * @example $scope = $provider->createScope(); try { $scope->run(fn () => Task::query()->count()); } finally { $scope->dispose(); }
 */
final class ServiceScope implements ServiceResolver
{
    /** @var array<string, object> */
    private array $instances = [];
    /** @var list<Disposable> */
    private array $disposables = [];
    /** @var array<string, string> */
    private array $resolving = [];
    private bool $disposed = false;

    public function __construct(private readonly ServiceProvider $provider)
    {
    }

    /**
     * 创建这个作用域的根容器。
     */
    public function provider(): ServiceProvider
    {
        return $this->provider;
    }

    public function get(string $id): object
    {
        $this->assertActive();
        return $this->provider->resolve($id, $this);
    }

    public function tryGet(string $id): ?object
    {
        return $this->has($id) ? $this->get($id) : null;
    }

    public function getAll(string $id): array
    {
        $this->assertActive();
        return $this->provider->resolveAll($id, $this);
    }

    public function has(string $id): bool
    {
        return $id === self::class || $this->provider->has($id);
    }

    /**
     * 在这个作用域里执行回调。登记了 Database 时，回调期间模型的静态查询（Model::query() 等）使用本作用域的 Database。
     * 绑定挂在本次调用的执行帧上，回调返回或抛错后恢复。
     *
     * @param callable $operation 接收本作用域。
     * @return mixed 回调的返回值。
     */
    public function run(callable $operation): mixed
    {
        $this->assertActive();
        $scope = $this;
        if (!$this->provider->has(Database::class)) {
            return $operation($scope);
        }
        return ModelContext::during($this->get(Database::class), static function () use ($operation, $scope): mixed {
            return $operation($scope);
        });
    }

    /**
     * 按创建的相反顺序释放本作用域创建的 Disposable。某个释放失败时其余仍会释放，最后抛出第一个异常。重复调用直接返回。
     */
    public function dispose(): void
    {
        if ($this->disposed) {
            return;
        }
        $this->disposed = true;
        $disposables = $this->disposables;
        $this->disposables = [];
        $this->instances = [];
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
     * @internal 由 ServiceProvider 调用。
     */
    public function scoped(ServiceDescriptor $descriptor, string $key): object
    {
        if (isset($this->instances[$key])) {
            return $this->instances[$key];
        }
        $scope = $this;
        $instance = ServiceProvider::guard($this->resolving, $key, $descriptor->id, static fn (): object => ServiceProvider::activate($descriptor, $scope));
        $this->instances[$key] = $instance;
        if ($instance instanceof Disposable) {
            $this->disposables[] = $instance;
        }
        return $instance;
    }

    /**
     * @internal 由 ServiceProvider 调用。
     */
    public function transient(ServiceDescriptor $descriptor, string $key): object
    {
        $scope = $this;
        $instance = ServiceProvider::guard($this->resolving, $key, $descriptor->id, static fn (): object => ServiceProvider::activate($descriptor, $scope));
        if ($instance instanceof Disposable) {
            $this->disposables[] = $instance;
        }
        return $instance;
    }

    private function assertActive(): void
    {
        if ($this->disposed) {
            throw new \LogicException('The service scope has been disposed');
        }
    }
}
