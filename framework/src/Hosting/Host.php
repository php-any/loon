<?php

declare(strict_types=1);

namespace Loom;

/**
 * 后台服务的生命周期，对应 .NET Generic Host 启动和停止 IHostedService 的那部分。
 * 用 ServiceCollection::addHostedService() 登记的服务按登记顺序启动，按相反顺序停止。
 *
 * 长期运行的命令（serve）用 run() 包住阻塞的监听：先启动后台服务，监听返回或抛错后再停止。
 *
 * 构造参数：
 * - $services（ServiceProvider）：根容器。后台服务是单例，从这里解析。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $host->run(static fn () => $server->run());
 */
final class Host
{
    /** @var list<HostedService> */
    private array $started = [];
    private bool $running = false;

    public function __construct(private ServiceProvider $services)
    {
    }

    /**
     * 按登记顺序启动。某个服务启动失败时，停止已经启动的服务，并继续抛出原来的异常。重复调用直接返回。
     */
    public function start(): void
    {
        if ($this->running) {
            return;
        }
        $started = [];
        try {
            foreach ($this->services->getAll(HostedService::class) as $service) {
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
     * 按相反顺序停止。某个 stop 失败时仍继续停止其余服务，最后抛出第一个停止异常。尚未启动时直接返回。
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
     * 启动后台服务，执行回调，再停止。回调抛出异常时仍然停止。
     *
     * @param callable $work 宿主运行期间的工作，例如阻塞监听。
     * @return mixed 回调的返回值。
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
     * @param list<HostedService> $started 已经启动的服务，按启动顺序。
     * @return ?\Throwable 第一个停止异常；全部成功时为 null。
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
