<?php

declare(strict_types=1);

namespace Loom;

/**
 * 随宿主启动和停止的后台服务。对应 .NET 的 IHostedService，这里用同步 start/stop。
 *
 * 构造参数：无，本类型未声明构造参数。
 *
 * 返回值：接口不能直接实例化；由实现类提供本契约。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $host->service(Worker::class);
 */
interface HostedService
{
    /**
     * 宿主开始工作时调用。重复启动由宿主挡住，实现仍应允许停止后再启动。
     *
     * 参数：无。
     *
     * @return void 无返回值。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $service->start();
     */
    public function start(): void;

    /**
     * 宿主停止时调用。不应打断已经在执行的业务，只停止后续工作。
     *
     * 参数：无。
     *
     * @return void 无返回值。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $service->stop();
     */
    public function stop(): void;
}
