<?php

declare(strict_types=1);

namespace Loom;

/**
 * 在独立循环里跑带 Timer 注解的无参可调用类。同一个定时器不重入，错过的节拍直接跳过。
 *
 * 每次节拍打开一个新的 ServiceScope，从中解析定时器并调用，结束后释放，对应 ASP.NET Core 后台服务里“每次工作建一个作用域”的写法。
 * 定时器要在节拍之间保留状态时，把它登记成 singleton（例如 #[Service(lifetime: 'singleton')]），此时它不能依赖 scoped 服务。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $runner = new TimerRunner($provider); $runner->register(\App\Console\Timers\Heartbeat::class); $runner->start();
 */
final class TimerRunner
{
    /** @var array<string, array{mode: string, seconds: int, cron: ?Cron, state: \stdClass}> */
    private array $timers = [];
    private bool $running = false;
    private int $generation = 0;

    /**
     * @param ServiceProvider $services 根容器，每次节拍从这里打开作用域。
     */
    public function __construct(private ServiceProvider $services)
    {
    }

    /**
     * 按目录推导类名并登记带 Timer 注解的类；运行期间禁止修改登记。
     *
     * @param string $directory 定时器 PHP 源码目录。
     * @param string $namespace 目录对应的命名空间。默认 App\Console\Timers。
     */
    public function discover(string $directory, string $namespace = 'App\\Console\\Timers'): void
    {
        foreach (ClassDiscovery::scan($directory, $namespace) as $class) {
            $this->register($class);
        }
    }

    /**
     * 校验唯一的 Timer 注解、调度声明和公开无参 __invoke，并登记。没有 Timer 注解返回 false；已登记返回 true。
     * 这里不创建定时器实例，实例在节拍里从作用域解析。
     *
     * @param string $class 定时器类名。
     * @return bool 是否登记为定时器。
     */
    public function register(string $class): bool
    {
        if ($this->running) {
            throw new \LogicException('Cannot register timers while running');
        }
        if (isset($this->timers[$class])) {
            return true;
        }
        $reflection = new \ReflectionClass($class);
        $attributes = $reflection->getAttributes(\Loom\Timer::class);
        if ($attributes === []) {
            return false;
        }
        if (count($attributes) !== 1) {
            throw new \LogicException('Duplicate Timer on ' . $class);
        }
        $timer = $attributes[0]->newInstance();
        if ($timer->isCron() && $timer->seconds >= 1) {
            throw new \LogicException('Timer cannot set both seconds and cron: ' . $class);
        }
        if (!$timer->isCron() && $timer->seconds < 1) {
            throw new \InvalidArgumentException('Timer interval must be at least one second: ' . $class);
        }
        if (!$reflection->isInstantiable() || !$reflection->hasMethod('__invoke')) {
            throw new \LogicException('Timer requires a concrete invokable class: ' . $class);
        }
        $method = $reflection->getMethod('__invoke');
        if (!$method->isPublic() || $method->isStatic() || $method->getNumberOfParameters() !== 0) {
            throw new \LogicException('Timer requires public __invoke() with no arguments: ' . $class);
        }
        $state = new \stdClass();
        $state->executing = false;
        if ($timer->isCron()) {
            try {
                $cron = new Cron($timer->cron, $timer->timezone);
            } catch (HttpError $error) {
                throw new \InvalidArgumentException('Invalid Timer cron on ' . $class . ': ' . $error->getMessage());
            }
            $this->timers[$class] = ['mode' => 'cron', 'seconds' => 0, 'cron' => $cron, 'state' => $state];
        } else {
            $this->timers[$class] = ['mode' => 'interval', 'seconds' => $timer->seconds, 'cron' => null, 'state' => $state];
        }

        return true;
    }

    /**
     * @return array<string, int> 已登记的定时器类名到周期秒数；Cron 定时器值为 0。
     */
    public function timers(): array
    {
        $out = [];
        foreach ($this->timers as $class => $timer) {
            $out[$class] = $timer['seconds'];
        }

        return $out;
    }

    /**
     * 在独立作用域里执行一次定时器。异常照常抛出。
     *
     * @param string $class 已登记的定时器类名。
     */
    public function tick(string $class): void
    {
        if (!isset($this->timers[$class])) {
            throw new \LogicException('Timer is not registered: ' . $class);
        }
        $this->services->withScope(static function (ServiceScope $scope) use ($class): void {
            $handler = $scope->get($class);
            if (!is_callable($handler)) {
                throw new \LogicException('Timer service is not callable: ' . $class);
            }
            $handler();
        });
    }

    /**
     * 为每个定时器 spawn 一个循环。重复调用直接返回。异常写到标准错误，不停止循环。
     */
    public function start(): void
    {
        if ($this->running) {
            return;
        }
        $this->running = true;
        $generation = ++$this->generation;
        $startedAt = microtime(true);
        foreach ($this->timers as $class => $timer) {
            $runner = $this;
            if ($timer['mode'] === 'cron') {
                $cron = $timer['cron'];
                \spawn(function () use ($runner, $class, $cron, $timer, $generation): void {
                    $state = $timer['state'];
                    $next = $cron->next(time() - 60);
                    while ($runner->running && $runner->generation === $generation) {
                        $now = time();
                        if ($now < $next) {
                            $remaining = $next - $now;
                            if ($remaining > 1) {
                                sleep(1);
                            } else {
                                usleep((int) max(1, $remaining) * 1000000);
                            }
                            continue;
                        }
                        if (!$runner->running || $runner->generation !== $generation) {
                            break;
                        }
                        if (!$state->executing) {
                            $state->executing = true;
                            try {
                                $runner->tick($class);
                            } catch (\Throwable $error) {
                                fwrite(fopen('php://stderr', 'w'), '[timer ' . $class . '] ' . $error->getMessage() . "\n");
                            } finally {
                                $state->executing = false;
                            }
                        }
                        try {
                            $next = $cron->next(max($next, time()));
                        } catch (\Throwable) {
                            sleep(1);
                        }
                    }
                });
                continue;
            }
            \spawn(function () use ($runner, $class, $timer, $generation, $startedAt): void {
                $period = $timer['seconds'];
                $state = $timer['state'];
                $next = $startedAt + $period;
                while ($runner->running && $runner->generation === $generation) {
                    $remaining = $next - microtime(true);
                    if ($remaining > 0) {
                        usleep((int) min(100000, ceil($remaining * 1000000)));
                        continue;
                    }
                    if (!$runner->running || $runner->generation !== $generation) {
                        break;
                    }
                    // 同时防范 stop()/start() 后仍在结束阶段的调用。
                    if (!$state->executing) {
                        $state->executing = true;
                        try {
                            $runner->tick($class);
                        } catch (\Throwable $error) {
                            fwrite(fopen('php://stderr', 'w'), '[timer ' . $class . '] ' . $error->getMessage() . "\n");
                        } finally {
                            $state->executing = false;
                        }
                    }
                    // 保留原始相位，丢弃执行期间错过的所有时钟节拍。
                    $next = $startedAt + (floor((microtime(true) - $startedAt) / $period) + 1) * $period;
                }
            });
        }
    }

    /**
     * 停止安排新的节拍。正在执行的那一次跑完为止。
     */
    public function stop(): void
    {
        $this->running = false;
    }
}
