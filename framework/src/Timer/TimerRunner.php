<?php

declare(strict_types=1);

namespace Loom;

/**
 * 在独立循环里跑带 Timer 注解的无参可调用对象。同一个定时器不重入，错过的节拍直接跳过。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $runner = new TimerRunner($services);
 */
final class TimerRunner
{
    private array $timers = [];
    private bool $running = false;
    private int $generation = 0;

    /**
     * 只保存默认容器。定时器表是空的，也还没开始跑。
     *
     * @param Container $services 注册时如果没有另外传入容器，就用它解析定时器实例。
     * @return void 不返回值。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $runner = new TimerRunner($services);
     */
    public function __construct(private Container $services)
    {
    }

    /**
     * 按目录推导类名并注册带 Timer 注解的类；运行期间禁止修改注册集合。
     *
     * @param string $directory 定时器 PHP 源码目录。
     * @param string $namespace 目录对应的命名空间，默认 App\Timers。默认值为 'App\\Timers'。
     * @return void 无返回值。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $runner->discover('/app/Timers', 'App\\Timers');
     */
    public function discover(string $directory, string $namespace = 'App\\Timers'): void
    {
        if ($this->running) {
            throw new \LogicException('Cannot discover timers while running');
        }
        foreach (ClassDiscovery::scan($directory, $namespace) as $class) {
            $this->register($class);
        }
    }

    /**
     * 验证唯一 Timer 注解、正周期及公开无参 __invoke，解析实例并保存独立执行状态。
     *
     * @param string $class 要登记的完整类名。没有 Timer 注解则跳过；注解多于一个、周期小于 1 秒或不能无参调用时抛异常。
     * @param ?Container $services 解析这个类用的容器。null 时改用构造时保存的容器，并且不记录 ServiceScope。默认值为 null。
     * @return ?object 定时器实例；无 Timer 注解返回 null，重复注册返回原实例。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $timer = $runner->register(\App\Timers\Heartbeat::class);
     */
    public function register(string $class, ?Container $services = null): ?object
    {
        if ($this->running) {
            throw new \LogicException('Cannot register timers while running');
        }
        if (isset($this->timers[$class])) {
            return $this->timers[$class]['handler'];
        }
        $reflection = new \ReflectionClass($class);
        $attributes = $reflection->getAttributes(\Loom\Timer::class);
        if ($attributes === []) {
            return null;
        }
        if (count($attributes) !== 1) {
            throw new \LogicException('Duplicate Timer on ' . $class);
        }
        $timer = $attributes[0]->newInstance();
        if ($timer->seconds < 1) {
            throw new \InvalidArgumentException('Timer interval must be at least one second: ' . $class);
        }
        if (!$reflection->isInstantiable() || !$reflection->hasMethod('__invoke')) {
            throw new \LogicException('Timer requires a concrete invokable class: ' . $class);
        }
        $method = $reflection->getMethod('__invoke');
        if (!$method->isPublic() || $method->isStatic() || $method->getNumberOfParameters() !== 0) {
            throw new \LogicException('Timer requires public __invoke() with no arguments: ' . $class);
        }
        $instance = ($services ?? $this->services)->get($class);
        if (!is_callable($instance)) {
            throw new \LogicException('Timer service is not callable: ' . $class);
        }
        // 派生的定时器共享对象。每个定时器独立管理状态，禁止修改共享的运行中定时器数组。
        $state = new \stdClass();
        $state->executing = false;
        $scope = $services === null ? null : $services->resolved(\Loom\ServiceScope::class);
        $this->timers[$class] = ['seconds' => $timer->seconds, 'handler' => $instance, 'state' => $state, 'scope' => $scope];
        return $instance;
    }

    /**
     * 为每个定时器启动独立循环，按起始相位调度并捕获处理异常；重复启动直接返回。
     *
     * 参数：无。
     *
     * @return void 无返回值，不阻塞等待所有定时器结束。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $runner->start();
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
                            $handler = $timer['handler'];
                            $scope = $timer['scope'];
                            if ($scope instanceof \Loom\ServiceScope) {
                                \Loom\ModelContext::during($scope, $handler);
                            } else {
                                $handler();
                            }
                        } catch (\Throwable $error) {
                            fwrite(fopen('php://stderr', 'w'), '[timer ' . $class . '] ' . $error->getMessage() . "\n");
                        } finally {
                            $state->executing = false;
                        }
                    }
                    // 保留原始相位，丢弃执行期间错过的所有时钟节拍。
                    $next = $startedAt + (floor((microtime(true) - $startedAt) / $period) + 1) * $period;
                }
                // 只由仍拥有这个代次的循环关闭。stop 之后再次 start 会换代，旧循环不能把新循环的作用域关掉。
                if ($timer['scope'] !== null && $runner->generation === $generation) {
                    $timer['scope']->close();
                }
            });
        }
    }

    /**
     * 设置停止标志，使运行循环停止安排后续节拍；不会强制中断正在执行的处理器。
     *
     * 参数：无。
     *
     * @return void 无返回值。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $runner->stop();
     */
    public function stop(): void
    {
        $this->running = false;
    }
}
