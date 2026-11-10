<?php

declare(strict_types=1);

namespace Loom\Monitoring;

/**
 * 按宿主入口通道开关监控会话收集，对应 config/monitoring.php 的 channels 节。
 */
final class MonitoringChannelOptions
{
    public bool $http = true;
    public bool $console = true;
    public bool $timer = true;
    public bool $queue = true;
    public bool $schedule = true;
    public bool $job = true;
    public bool $tcp = true;
    public bool $grpc = true;
}
