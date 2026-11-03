<?php

declare(strict_types=1);

namespace Loom;

/**
 * 按登记顺序启动后台服务，并按相反顺序停止。对应 .NET Generic Host 的托管服务生命周期。
 *
 * 构造参数：
 * - $container（Container）：解析类名形式后台服务的容器，应为应用根容器。
 *
 * 返回值：使用 new 构造时得到 Host 实例；根作用域把它注册为 singleton。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $services->get(Host::class)->service(Worker::class);
 */
final class Host
{
    /** @var list<HostedService|string> */
    private array $registrations = [];
    /** @var list<HostedService> */
    private array $started = [];
    private bool $running = false;

    /**
     * 保存解析后台服务所用的容器。
     *
     * @param Container $container 应用根容器。类名形式的服务从这里解析。
     * @return void 无直接返回值。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $host = new Host($services);
     */
    public function __construct(private Container $container)
    {
    }

    /**
     * 登记一个后台服务。可以传入实例，或尚未解析的类名、接口名。
     * 类名在 start 时解析；尚未注册的具体类会登记为 singleton。scoped 或 transient 会被拒绝。
     *
     * @param HostedService|string $service 服务实例，或容器中的服务标识。
     * @return void 无返回值。宿主已经启动时抛出 LogicException。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $host->service(Worker::class);
     */
    public function service(HostedService|string $service): void
    {
        if ($this->running) {
            throw new \LogicException('Cannot register a hosted service after the host has started');
        }
        if (is_string($service) && $service === '') {
            throw new \InvalidArgumentException('Hosted service name must not be empty');
        }
        $this->registrations[] = $service;
    }

    /**
     * 按登记顺序启动。某个服务启动失败时，停止已经启动的服务，并继续抛出原来的异常。
     * 重复调用直接返回。
     *
     * 参数：无。
     *
     * @return void 无返回值。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $host->start();
     */
    public function start(): void
    {
        if ($this->running) {
            return;
        }
        $started = [];
        try {
            foreach ($this->registrations as $registration) {
                $service = $this->resolve($registration);
                $service->start();
                $started[] = $service;
            }
        } catch (\Throwable $error) {
            $this->stopAll($started);
            throw $error;
        }
        $this->started = $started;
        $this->running = true;
    }

    /**
     * 按相反顺序停止。某个 stop 失败时仍继续停止其余服务，最后抛出第一个停止异常。
     * 尚未启动时直接返回。
     *
     * 参数：无。
     *
     * @return void 无返回值。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $host->stop();
     */
    public function stop(): void
    {
        if (!$this->running) {
            return;
        }
        $started = $this->started;
        $this->started = [];
        $this->running = false;
        $failure = $this->stopAll($started);
        if ($failure !== null) {
            throw $failure;
        }
    }

    /**
     * 启动宿主，执行回调，再停止。回调抛出异常时仍然停止。
     *
     * @param callable $work 宿主运行期间要执行的工作，例如阻塞监听。
     * @return mixed 回调的返回值。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $host->run(static function (): void { $server->serve(); });
     */
    public function run(callable $work): mixed
    {
        $this->start();
        try {
            return $work();
        } finally {
            $this->stop();
        }
    }

    /**
     * 解析登记项。实例原样返回；类名必须是 singleton。
     *
     * @param HostedService|string $service 登记时保存的实例或服务标识。
     * @return HostedService 可启动的服务。
     *
     * 内部调用示例（仅类内使用）：
     * @example $service = $this->resolve($registration);
     */
    private function resolve(HostedService|string $service): HostedService
    {
        if ($service instanceof HostedService) {
            return $service;
        }
        if (!$this->container->has($service)) {
            if (!class_exists($service)) {
                throw new \LogicException('Hosted service is not registered: ' . $service);
            }
            $reflection = new \ReflectionClass($service);
            if (!$reflection->isInstantiable()) {
                throw new \LogicException('Hosted service is not registered: ' . $service);
            }
            $this->container->singleton($service);
        } elseif ($this->container->lifetime($service) !== 'singleton') {
            throw new \LogicException('Hosted service must be a singleton: ' . $service);
        }
        $instance = $this->container->get($service);
        if (!($instance instanceof HostedService)) {
            throw new \LogicException('Hosted service must implement HostedService: ' . $service);
        }
        return $instance;
    }

    /**
     * 按相反顺序停止，收集第一个异常。
     *
     * @param list<HostedService> $started 已经成功 start 的服务，按启动顺序排列。
     * @return ?\Throwable 第一个 stop 异常；全部成功时返回 null。
     *
     * 内部调用示例（仅类内使用）：
     * @example $failure = $this->stopAll($started);
     */
    private function stopAll(array $started): ?\Throwable
    {
        $failure = null;
        foreach (array_reverse($started) as $service) {
            try {
                $service->stop();
            } catch (\Throwable $error) {
                $failure ??= $error;
            }
        }
        return $failure;
    }
}
