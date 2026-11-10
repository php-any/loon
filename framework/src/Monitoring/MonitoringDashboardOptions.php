<?php

declare(strict_types=1);

namespace Loom\Monitoring;

/** 对应 config/monitoring.php 的 dashboard 节。 */
final class MonitoringDashboardOptions
{
    public bool $enabled = false;

    /** URL 前缀，不含首尾斜杠。 */
    public string $path = 'monitoring';
}
