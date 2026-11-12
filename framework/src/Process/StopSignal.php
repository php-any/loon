<?php

declare(strict_types=1);

namespace Loom;

/**
 * 长驻进程的停止信号与用户退出回调。框架不做排空或 HostedService 编排；收到信号后可选执行应用登记的回调，然后退出。
 *
 * 在 loom.php 用 ApplicationBuilder::onStop()，或在 build() 之后用 Application::onStop() 登记。
 * 长驻命令在阻塞前调用 listen()。
 */
final class StopSignal
{
    /** @var list<\Closure> */
    private array $handlers = [];
    private bool $listening = false;
    private bool $dispatched = false;

    /**
     * 登记停止时要执行的回调。多次登记按顺序累积执行。未登记时收到信号直接退出。
     */
    public function onStop(callable $handler): void
    {
        $this->handlers[] = $handler(...);
    }

    public function hasHandler(): bool
    {
        return $this->handlers !== [];
    }

    /**
     * 在当前进程监听 SIGINT/SIGTERM（Windows 上含 SIGBREAK）。重复调用无效。
     */
    public function listen(): void
    {
        if ($this->listening) {
            return;
        }
        $this->listening = true;
        $signal = $this;
        \spawn(static function () use ($signal): void {
            $signals = [\SIGINT, \SIGTERM];
            if (\defined('SIGBREAK')) {
                $signals[] = \SIGBREAK;
            }
            try {
                \Signal\wait($signals);
            } catch (\Throwable) {
                return;
            }
            $signal->dispatch();
        });
    }

    /**
     * @param bool $exit 为 true 时以状态码 0 结束进程（信号路径）；测试传 false。
     */
    public function dispatch(bool $exit = true): void
    {
        if ($this->dispatched) {
            if ($exit) {
                exit(0);
            }
            return;
        }
        $this->dispatched = true;
        foreach ($this->handlers as $handler) {
            $handler();
        }
        if ($exit) {
            exit(0);
        }
    }
}
