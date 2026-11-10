<?php

declare(strict_types=1);

namespace Loom\Monitoring;

/**
 * 在已开启的监控会话内，控制哪些 record* 调用写入缓冲，对应 config/monitoring.php 的 record 节。
 */
final class MonitoringRecordOptions
{
    public bool $sql = true;
    public bool $http = true;
    public bool $console = true;
    public bool $events = true;
}
