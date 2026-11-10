<?php

declare(strict_types=1);

namespace Loom\Monitoring;

/** 对应 config/monitoring.php 的 storage 节。 */
final class MonitoringStorageOptions
{
    /** connections 中的连接名；null 表示应用默认库。 */
    public ?string $connection = null;

    public int $retain_hours = 24;
}
