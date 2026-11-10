<?php

declare(strict_types=1);

namespace Loom\Monitoring;

use Loom\ServiceScope;

/**
 * 应用注册的自定义观测器；在会话 begin 之后 start，dispose 之前 stop。
 */
interface MonitoringWatcher
{
    public function name(): string;

    public function enabled(MonitoringOptions $options): bool;

    public function start(ServiceScope $scope, Monitoring $monitoring): void;

    public function stop(ServiceScope $scope, Monitoring $monitoring): void;
}
